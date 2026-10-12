<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0003: DPS identity, reservation and cross-installation safety

Status: Proposed for independent review. **Not an authorization to implement, issue real NFS-e, or close #423.**
Baseline: akaunting-nfse main `3739466804aa2b398842748c06b749c482b2f9ad` (2026-10-12).
Scope: #423; coordinate with #417, #421 and #429.

## Evidence and constraints

- Official [DPS guidance](https://www.gov.br/nfse/pt-br/saiba-mais/como-a-nfs-e-e-gerada/o-que-e-dps) states that numbering belongs to the taxpayer and is sequential and increasing.
- Official [production documentation](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual) lists `NFSe-ESQUEMAS_XSD-v1.01-20260209` and February 2026 Annex I. The [restricted documentation](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/documentacao-tecnica-rtc-producao-restrita) lists a later `v1.01-20260727` package. Publication does not prove deployment across endpoints.
- The pinned `nfse-php/references/schemas/nfse/1.01` snapshot documents `TSIdDPS` as `DPS` + emission municipality (7) + federal registration type (1) + registration (14) + series (5) + number (15). `TSNumDPS` requires a 1–15 digit positive decimal without a leading zero. Local environment values are 1 for production, 2 for restricted/homologation.
- February's `TSSerieDPS` maximum is five characters, but its pattern `^0{0,4}\\d{1,5}$` has incompatible XML Schema anchors; `nfse-php` documents a narrow validator exception. Do not silently repair the official schema or assume a special series range for API issuers.
- Confirm official Annex I rules for duplicate DPS, reserved-but-unsent, rejected DPS reuse, series range/default, gaps and municipality scope before adopting production policy. The rule spreadsheets and official ZIP content were not independently downloaded during this review. In particular, E0312 is not proof of a number collision.
- Third-party comparisons: `brasil-fiscal/nfse` and `nfse-nacional/nfse-php` expose DPS series/number to the caller and focus on protocol concerns. Their examples do not prove a persistent shared number allocator.

## Findings in the current code

- `InvoiceDpsIdentity`: fixed series `00001`, number from Akaunting invoice ID.
- `FiscalGroupDpsIdentity`: series `00002`, number from local group row ID.
- `SubstitutionDpsNumber`: prefix 7 plus receipt ID; `InvoiceController::dpsNumberForReemit`: prefix 9 plus cancelled receipt ID. These are internal conventions, not a unified fiscal sequence.
- `EmissionAttemptJournal` already records pending before POST, associates receipts transactionally, and performs read-only reconciliation for pending/ambiguous attempts. Preserve it; do not implement a second retry engine.
- Its unique index `(company_id, environment, dps_identifier, attempt_number)` does not enforce uniqueness for two Akaunting companies representing the same fiscal issuer or across separate databases. `nfse_fiscal_group_identities` only has `(invoice_id, group_key)` uniqueness.
- `RecoverInvoiceEmission::dpsIdentifier` manually duplicates the ID building already available in `nfse-php::Support::DpsIdentifier`. Reuse the installed scoped public contract only after verifying actual Composer pin/runtime.
- `InvoiceDpsBuilder` has default series/number `00001`/`1`. Any new emission pathway must fail closed without an explicit valid reservation.

## Decision proposed

Separate immutable identities for (a) Akaunting commercial document, (b) reserved DPS, and (c) SEFIN-assigned NFS-e receipt/access key. Never rewrite authorized NFS-e numbers, XML, events or replacement links.

`company_id` is the tenant authorization boundary; **not** the fiscal issuer identity. Define fiscal issuer from emitting municipality, registration type and normalized federal registration. Partition the local sequence by fiscal issuer, target environment and series. Do not strip alphabetic registration characters if supported by the applicable runtime.

Introduce an application-layer `DpsReservationService::reserve(request): ReservedDpsIdentity` with explicit company owner, fiscal profile snapshot, environment, policy-approved series, immutable logical operation key and optional original fiscal receipt. Origin (manual, automatic, group, batch) is audit metadata, not part of the operation key. Return the same durable reservation for repeated compatible requests; reject conflicting payload/context.

Create a durable counter and reservation ledger, linked to existing `EmissionAttemptJournal`, with unique counter key `(environment, issuerMunicipality, registrationType, registration, series)`, unique allocated number under that key, and unique logical operation key within the appropriate company/issuer scope. Bind the reservation to its owner and immutable operation type. Store no certificates, secrets or full DPS XML.

In a real DB transaction: authorize owner, lock/atomically update the counter, reserve the next increasing 1–15 digit number, persist immutable owner/operation mapping, commit; only then build the DPS. Retry **only a failed reservation transaction** after a uniqueness conflict, never retry POST. Concurrent initial counter creation must use DB uniqueness and bounded transaction retries. Test with multiple actual DB connections, not only SQLite mocks.

Immutable logical units: normal invoice issuance, each real fiscal group, each replacement of an original authorized receipt, and each reissue after cancellation. New fiscal operations receive new DPS numbers while retaining original receipt links. A repeat of one logical unit uses its previous reservation; a payload change during unresolved transmission is a conflict.

First-POST claim is atomic and durable. A crash after claim is **ambiguous even if transmission might not have begun**; lease expiry never licenses a new POST. On 5xx, timeout, missing authorized number/access key, unknown failure or persistence exception: perform only read-only DPS reconciliation. Official rejection is stored with code/status/fingerprint, without automatically reassigning or reusing its number; allow changed-payload retries only after a versioned official policy and specific tests establish safe behavior. Local blocked-before-POST is distinct from fiscal rejection. Never downgrade authorized status.

**Distributed limitation:** local SQL guarantees uniqueness only among installations sharing that storage. Multiple independent installations representing one taxpayer must either use an atomic shared coordinator or demonstrate enforced, exclusive, nonoverlapping series ownership. Without this evidence **block production issuance**, do not falsely promise global uniqueness. Do not add a microservice by default. A restore, old external emitter or second local company must not silently reset the counter. For first production use, require explicit series ownership, starting-value evidence and coordination mode; do not seed from invoice IDs or local receipts alone.

## Integration boundaries

- #417 owns global Vault and per-company fiscal-profile administration, not sequence generation.
- #421 owns authoritative company context across HTTP, CLI, jobs, cancellation, recovery and ADN. Reservation must not infer job ownership from HTTP session company.
- #429 owns the common last mutable fiscal/municipal preflight. A rejection blocks POST but must not substitute a different DPS number.
- `nfse-php` owns versioned XSD, ID formation, XML, signing and SEFIN/ADN transport; `akaunting-nfse` owns business operation key, tenant authorization, reservation and lifecycle.
- No new public API, scheduling, real tax emission, or historical rewriting in this ADR.

## Falsifiable acceptance tests and gates

1. Duplicate HTTP/job/group/batch requests for the same logical unit reuse one reservation and fake SEFIN observes at most one initial POST; changed payload during pending/ambiguous fails.
2. Concurrent distinct units on two real SQL connections allocate strictly increasing, nonduplicated numbers; simultaneous first-row creation and lock collisions do not reuse numbers.
3. Two companies representing one fiscal issuer share numbering but cannot read each other's operations or certificates. Different issuers can use the same series/number.
4. Same issuer with independent databases fails closed without externally proven coordination; separate approved series or shared coordinator passes.
5. Production and restricted sequence isolation; zero production POSTs in tests; explicit versioned schema capability.
6. Crash before reservation, after commit, during claim, after successful remote POST but before persistence: no blind second POST; recovery uses fake read-only query.
7. Structured duplicate-ID responses never cause fresh issuance automatically; unrelated E0312 rejection is not treated as duplicate identity.
8. Cancel, reissue, substitution preserve immutable original NFS-e number/XML and use a new DPS for a genuinely new operation.
9. Invalid/overflow number, missing/contested series ownership, missing fiscal profile, wrong tenant, removed profile and Vault outage fail without cross-company data or secret disclosure.
10. Run focused unit/Feature on real Akaunting with fake SEFIN, concurrent integration tests, affected full suite and final HEAD CI; CODEOWNER review required. Restricted-environment operational validation, if authorized, is separate from synthetic automated tests.

Open decisions: download and validate active official Annex I and XSD ZIPs by environment/checksum; document rules for rejection and gaps; confirm series ownership across installations; review history occupancy without modifying authorized fiscal documents; independent review; approve minimal issue decomposition via `create-issues-and-epics` before any issue write.
