<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Municipal competence evidence and follow-up to #374

## Decision boundary and normative sources (reviewed 2026-10-09)

**Municipal parameters are consultative; SEFIN authorizes the NFS-e.** The
official *Manual dos Contribuintes - Guia para utilização das APIs do Emissor
Público Nacional* (government production library, PDF filename
`manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf`,
page 3, sections 1.2 and 1.3.2) expressly separates obtaining municipal
parameters to fill out the DPS from business validation of a submitted DPS at
`POST /nfse`, which issues the NFS-e or returns a structured rejection.

* Official, current production library (updated 2026-08-15):
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
* Official taxpayer API manual:
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf
* Official API endpoints by environment, published 2025-10-01, updated 2026-08-20:
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao
* LC 116/2003, art. 3 (including express place-of-incidence exceptions and
  certain cases involving multiple municipalities):
  https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm
* Federal MEI documentation and resolutions:
  https://www.gov.br/nfse/pt-br/biblioteca/legislacao-aplicavel-ao-mei
* Government annex used by SEFIN for the DPS business rules:
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/anexo_i-sefin_adn-dps_nfse-snnfse-v1-01-20260209.xlsx

The government annex is listed as version **v1.01 (2026-02-09)**.
Its individual E0312 row and any MEI exception were **not independently
machine-verified in this review**. The text of E0312 was corroborated in
commercial vendor explanations, which are **not normative sources**; this
implementation recognizes E0312 only when observed in a structured, typed
SEFIN issuance error for the exact submitted DPS. It never infers an E0312
from the national catalog, a rate, or a 404. No MEI exemption/prohibition,
special regime or unsupported LC 116/cTribNac correlation is hardcoded.

## What is an admissibility decision?

| Observed evidence | Can establish | Cannot establish |
| --- | --- | --- |
| National six-digit catalog hit | Code exists nationally | Municipal acceptance for the DPS competence |
| ADN municipal rate, convention, retention or special regime | Advisory parameter information | Municipal permission or refusal |
| ADN HTTP 404, empty/partial response | Unknown municipal parameter availability | Negative authorization |
| Failed GET / stale, exact-context cached snapshot | Advisory last-known values and fallback reason | Current normative validity |
| Authenticated SEFIN issuance 400/422 with structured E0312 | Rejection **of that exact DPS** | Blacklist for other DPS, taxpayers, regimes, codes or competencies |
| Authenticated SEFIN issued/reconciled ReceiptData | NFS-e for that exact DPS was issued | Advance approval for other DPS |
| SEFIN timeout, HTTP 5xx or ambiguous response | Outcome remains uncertain; read-only DPS recovery needed | Issuance rejection or safe automatic retry |

The pure `MunicipalAdmissibilityEvaluator::evaluate(context, evidence)` returns:
`decision = authorized | rejected | unverifiable`, `authorized`,
`can_attempt`, `binding = exact | none`, `reason`, normalized context and
source metadata. Most preflight cases are **unverifiable and non-blocking**;
a later issued receipt is retrospective, not pre-authorization.

A raw array claiming `kind = sefin_issuance_result`, even with the correct
host, HTTP code and keys, cannot create a decisive outcome. Use typed
`OfficialMunicipalIssuanceEvidence::fromRejection(IssuanceException, ...)`
or `::fromReceipt(ReceiptData, ...)`, **only at a trusted gateway boundary**,
after independently binding the final submitted DPS ID and SHA-256 to the
tenant and fiscal context. The factory is an internal trust boundary, not a
cryptographic validation of untrusted upstream material. Structured errors
are parsed by the existing `OfficialIssuanceRejection`. A 503 is not a
rejection, even with an E0312-like JSON body. The success SDK DTO does not
expose HTTP status: the value remains null, never fictitiously set to 201.

`FiscalProfileEmissionReadiness` remains a **national profile** preflight.
It no longer accepts an arbitrary municipal decision array as a veto.
Issue #376 must consume verified evidence and revalidate the exact
operation at the final POST boundary, including groups and recoveries.

## Deriving the actual ISS incidence municipality

The pure `MunicipalIncidenceResolver` receives trusted fiscal facts for the
selected invoice/group. `InvoiceMunicipalEvidenceContextResolver` adapts
`App\\Models\\Document\\Document`, its actual `issued_at` through
`InvoiceDpsIdentity::competenceDate()`, the **selected** group's six-digit
national code and three-digit municipal complement (or `000`), company ID
from the invoice and company-scoped environment.

LC 116/2003 article 3 normally uses the service provider's **establishment**
but has exceptions (such as construction at 7.02/7.19/14.14, healthcare at
4.22/4.23, place of certain events at item 12 and transportation at item 16).
The code resolves the ordinary case only when
`provider_establishment_confirmed=true` is supplied by a trusted adapter;
a configured address alone is **not** legal proof. For express exceptional
items, multi-municipality service, foreign-service incidence, special
ISS-taxation or missing establishment/location data it returns
`municipio_ibge=null` and a stable `reason` unless an explicit
`incidence_municipality` is accompanied by
`incidence_basis=documented_fiscal_context`.

The supplied documentary basis is a **caller assertion that must be verified
by the fiscal integration**; this module does not invent a corresponding
legal decision, determine apportionment or add an editable field. If no
competence or verifiable incidence municipality exists, the evaluator returns
`unverifiable`; it never falls back to today's date or the provider's city.
MEI / Simples regimes do not create blanket allowance or refusal.

## Municipal snapshots, provenance and isolation

`ItemMunicipalValidationResolver::resolveMany(..., competence: 'YYYY-MM-DD')`
queries exact company, sandbox/production, queried IBGE municipality,
full **nine-digit** code and competence. The item-screen default is **today
only**, not evidence about any invoice's tax-incidence municipality. The
rate comparison remains advisory; even a perfect rate match cannot become
`authorized` or a normative `valid` municipal decision.

Migration `2026_10_09_000001` adds optional
`nfse_municipal_parameter_snapshots.source_provenance` (JSON). The
`MunicipalParameterSnapshotStore` preserves query provenance per endpoint:
official endpoint URL, **observed** HTTP 404, response/404 outcome, and
contract version or effective period only if published. The current SDK
does not return successful HTTP status or reliable version/validity:
`http_status`, `contract_version`, `valid_from` and `valid_until`
are **null** when unknown. No version/status is backfilled into old
snapshots, and historical rows remain untouched. Source keys and query
identity are returned in `meta`; item advisory `source` includes the
queried municipality explicitly, **not** an inferred ISS incidence.

On a failed official query, cache fallback is permitted only for the exact
identity and is labeled `source=cache`, `stale=true`,
`fallback_reason=official_query_failed`. An unmatched failure propagates;
a failed GET **never** turns into an authorization/rejection and cannot
trigger a fiscal POST retry.

No `nfse-php` protocol change was required: the production SDK supplies
typed issuance exceptions, receipts and the request contexts needed here.
The module records status **only when the SDK actually exposes it**.

## Validation and remaining integration boundary

Pure unit tests cover default and exceptional LC 116 incidence, explicit
documented incidence, malformed/unknown contexts, typed SEFIN E0312 and
issued receipts, spoofed objects, changed DPS/company/environment/municipality/
competence/code/hash, 404, rate, stale and unavailable sources. Database
feature tests cover actual persisted invoice groups and competence, historic
snapshots and company/environment/municipality/nine-digit service/competence
isolation, fallback and legacy provenance.

**Not part of this PR:** integrating municipal decisions into manual,
automatic, grouped or batch issuance; editing tax inputs/UI; inventing a
municipal administration API not present in official endpoints; reworking
retries, RTC/CSLL or the SDK. Those actions belong to #376. An authenticated
SEFIN result must be captured and bound at the emission boundary, not
synthesized from previously cached parameters.
