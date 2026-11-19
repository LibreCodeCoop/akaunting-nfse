<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DPS reservation and irreversible first-transmission contract

This page describes the reservation and emission boundary implemented by the
module. Provisioning an authority, activating a fiscal series and carrying
out a real fiscal transmission require separate operational authorization.
See [ADR 0003](adr/0003-dps-identity-and-reservation.md) for the adopted
architecture.

## Sources of truth

| Required input | Authority |
| --- | --- |
| Invoice owner | Persisted Akaunting `documents.id` and `documents.company_id`, with `type=invoice`; an input `company_id` alone is not authority |
| Caller | Authorized HTTP actor or independently authorized CLI/queue principal, bound to the persisted invoice; workers do not infer ownership from a web session |
| Fiscal issuer | Active, company-owned profile with issuing municipality, registration type and normalized federal registration, resolved by `DpsFiscalIssuer` |
| Environment and series | Deliberately activated environment/series tied to the fiscal profile and authority; neither invoice IDs nor arbitrary client parameters select a default |
| Logical operation | Invoice, verified fiscal group, reissuance of a cancelled receipt or substitution of an authorized original receipt |
| Idempotency | `DpsOperationKey::forInvoice(companyId, invoiceId, environment, groupKey, cancelledReceiptId, substitutedReceiptId)`; transport origin is not part of the key |
| Fiscal validity | Final company-scoped municipal, temporal, invoice and tax preflight for the actual DPS before its first POST |

`CompanyProfileDpsReservationAuthorizer::assertCanReserve()` checks the
authorized company/invoice and exact fiscal profile, environment and
normalized series. Matching CNPJ or a company selected in another web
session is insufficient. Repeated use of an existing reservation still
requires authorization, without disclosing other companies' records.

`DpsReservationService::reserve()` produces immutable
`reservation_id`, `company_id`, `invoice_id`, `series` and `number`.
For an existing compatible logical operation it returns the same
reservation; a conflicting fiscal context is rejected. Newly allocated
numbers are never silently recycled when preflight later blocks a POST.
The reservation ledger contains no certificate, private key, secret or
full fiscal payload.

## Issuance sequence and recovery

1. Resolve the persisted company-owned invoice, fiscal group or original
   receipt, the active fiscal profile, certificate and explicit target
   environment. Reject missing, suspended, mismatched and unauthorized
   inputs without fallback.
2. Derive the stable operation key and reserve the next number from an
   independently authorized series. The same compatible operation
   reuses the reservation. A newly reserved number may be consumed
   even when the subsequent preflight prevents transmission.
3. Build the DPS from the **reserved** series/number and the verified
   fiscal-profile snapshot. `nfse-php` owns schema, signing, fiscal
   identifier formation and transport; the installed scoped version
   must match the target environment's supported contract.
4. Link `nfse_emission_attempts.dps_reservation_id` to the reservation
   for new operations, without rewriting earlier attempts. The
   `EmissionAttemptJournal` persists `pending`, checks the final DPS
   at the last mutable boundary and durably claims its **first** fiscal
   POST through `DpsIssuanceIdentity::claimFirstPost()`. A blocked
   preflight performs **zero** fiscal POSTs.
5. Keep outcomes distinct: `authorized` requires a persisted valid
   receipt; `rejected` requires a confirmed official rejection;
   `ambiguous` covers uncertain transport or persistence;
   `technical_failure` applies to a proven local failure before
   transmission. Receipt persistence and authorization must not
   degrade a previously authorized state.
6. For timeout, crash, 5xx with uncertain outcome, absent access key
   or partial commit, reconcile the **same DPS** read-only. Neither a
   new request nor claim lease expiry grants another POST. Recoveries
   use explicit, company-scoped read permissions.
7. Cancellation does not renumber an authorized document. Reissuance
   after cancellation and substitution of an authorized receipt have
   their own logical keys and distinct reserved DPS numbers. Original
   receipts, XMLs, events and archived artifacts remain immutable.

`IssueInvoiceNfse::issue()` is a low-level transport helper, not a
standalone public issuance entrypoint. Manual, automatic, grouped,
batch, reissuance and substitution callers use the journal and its
claim boundary. There is no independent retry journal.

### Correcting definitively rejected substitutions

A confirmed SEFIN HTTP 400/422 rejection **E1235** (XML schema) or
**E0713** (incompatible `indTotTrib`/`pTotTribSN` for a non-Simples
issuer) does **not** release the submitted number or its first-POST
claim. The module permits an explicit, separately authorized corrected
successor of the *same valid original authorized NFS-e* using a
distinct operation key incorporating the confirmed rejection attempt.
The previous rejection remains immutable.

The corrected payload must pass the actual environment's XSD before a
new reservation. For E0713 the mandatory `totTrib` information uses
three legitimately sourced `pTotTrib` percentages; neither invented
zeros nor `indTotTrib` are acceptable substitutes. Missing independent
source evidence blocks before allocation. A repeat of the new logical
operation follows its existing reservation/recovery path. Other
rejections and ambiguous outcomes do not authorize this successor or
any automatic retransmission.

## MySQL authority and operational limits

The supported topology is **one Akaunting installation with one MySQL
database and multiple companies**. `ConfiguredDpsCoordinator` opens an
independent PDO connection to the **same** Akaunting schema. The
authority transaction allocates and commits the number before the
invoice-side transaction records the local reservation. The coordinator
checks the approved issuer/environment/series, installation membership,
fencing generation, reference and immutable operation key.

When the authority commits but the local reservation fails, a replay
must reconcile the central operation **read-only**; it cannot create a
new local reservation or transmit. Existing reservations require an
exact read-only `assertExisting()` match. The first-POST claim is
likewise irreversible once committed, even if the response disappears.

The migration creates authority tables with inactive status. An
active-looking local series or a matching `coordination_reference`
does not activate the authority. A missing coordinator, unknown
historical floor, revoked generation or wrong profile fails closed.
Series numbering is issuer-scoped rather than company-scoped: two
company records of the same taxpayer share the sequence while retaining
strict invoice and certificate isolation.

This authority cannot prevent a nonparticipating external emitter from
reusing the same series. A full-database rollback can also erase both
local and authority records simultaneously. Restore requires emission
shutdown, independent historical evidence, read-only SEFIN
reconciliation and deliberate generation/series reactivation. Do not
seed numbers from invoice IDs, assume one negative official lookup
proves the complete historical high-water, or use the authority with
independent Akaunting databases.

The national `TSIdDPS` combines municipality, registration type,
federal registration, series and number; the positive `TSNumDPS`
has at most 15 decimal digits. Series normalization is an application
convention rather than an officially prescribed API default.
Environment-specific XSD publication does not prove activation in a
SEFIN endpoint. Unresolved official rejection or gap policy is never
interpreted as permission to recycle a number.

## Verification references

The module includes `DpsReservationServiceTest`,
`EmissionAttemptJournalTest`, real-MySQL
`SharedMysqlDpsSeriesCoordinatorTest` and fake-SEFIN feature tests
for uniqueness, company scope, concurrent allocation, claim-at-most-once,
partial commits, unsafe replay and rejection handling. These tests
protect the software contract; they do not certify series ownership,
regulatory compliance or live SEFIN enablement.

For provisioning, permissions, number adjustment and recovery see
[DPS coordination operations](dps-distributed-coordination.md).
For company configuration and issuance controls see
[configuration and operations](operacao-e-configuracao.md).
For fiscal eligibility and municipal evidence see
[fiscal safety](fiscal-safety.md).
