<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DPS reservation handoff for the multi-company emission boundary

Status: **integration specification only**. The local reservation kernel is not wired
into fiscal transmission. This document does not authorize series activation,
production emission or completion of #423.

Scope: #423, fiscal profile ownership in #417, authoritative company context in
#421 and the last mutable preflight in #429. The existing
[ADR 0003](adr/0003-dps-identity-and-reservation.md) remains the architectural
source for unresolved external coordination and rejected-number policy.

## Sources of truth and required inputs

| Input | Authoritative source / validation |
| --- | --- |
| Fiscal operation owner | Persisted Akaunting `documents.id` + `documents.company_id` with `type = invoice`; never a client-supplied `company_id` alone |
| Caller | #421's actual authorized HTTP principal or independently authorized CLI/job principal, bound to the persisted owner; no HTTP session inference in workers |
| Fiscal identity | #417's persisted, authorized fiscal profile for the owner: issuing municipality, registration type and normalized federal registration; resolve with `DpsFiscalIssuer` |
| Environment and series | Explicitly approved profile/environment/series activation snapshot; never defaults from the invoice ID, origin or an arbitrary API parameter |
| Fiscal operation | Persisted invoice, existing validated fiscal group key (when applicable), or owned original cancelled/substituted receipt; distinguish each logical operation |
| Idempotency | `DpsOperationKey::forInvoice(companyId, invoiceId, environment, groupKey, cancelledReceiptId, substitutedReceiptId)` using persisted identifiers, not caller origin |
| Origin | Auditable label (`manual`, `manual_group`, `automatic`, `reemit`, `substitution`, future scheduler/API); not part of fiscal identity or idempotency |
| Preflight | #429 pure assessment of the resolved company's immutable fiscal/municipal and invoice context, by actual group, rechecked at the last boundary before any first POST |

For each **new** reservation, #421 must inject a concrete
`DpsReservationAuthorizer` implementing this exact contract:

```php
public function assertCanReserve(
    int $companyId,
    int $invoiceId,
    DpsFiscalIssuer $issuer,
    int $environment,
    string $series,
): void;
```

The authorizer must independently prove that the authenticated/delegated actor
may operate on that invoice and the corresponding fiscal profile, including
the **specific target environment and normalized series**. A matching CNPJ,
a request field, a company selected in an unrelated HTTP session or a row
labelled `shared_database` is not permission. Denials must occur before
a new sequence mutation and must not reveal another tenant's reservation.

For a **new** fiscal operation, invoke the existing kernel as follows only
after owner, fiscal profile, series entitlement and nonmutating preflight have
been independently validated:

```php
$reserved = $reservations->reserve(
    $companyId, $invoiceId, $issuer, $environment,
    $approvedSeries, $persistedOperationKey, $origin,
);
// reservation_id, company_id, invoice_id, series, number
```

This API is not a certificate or profile resolver. #421 must retain the
verified fiscal-profile snapshot and ensure `reservation_id` belongs to
the exact issuer/environment/series/owner/operation when building or
reconciling a DPS. Production code must not install an allow-all authorizer.

## Integration and irreversible transitions

1. Load the persisted invoice and original receipt/group when relevant. #421
   resolves authorized company/profile/certificate and target environment.
   Reject absent, suspended, cross-tenant or inconsistent profiles with no
   fallback. #429 evaluates all applicable business/fiscal checks without POST.
2. Derive the stable operation key and obtain the reservation. Repeated
   compatible calls receive the **same** reservation even after a series is
   suspended, but must still pass caller authorization; distinct operations
   must never reuse its number. A changed immutable context conflicts.
3. Build the DPS using **only** the returned reserved series/number and the
   verified fiscal-profile snapshot. The canonical `nfse-php` contract owns
   XML, signing, protocol fields and identifier formation; verify its scoped
   Composer revision before replacing legacy identifier formation.
4. Link `nfse_emission_attempts.dps_reservation_id` to the verified reserved
   DPS for **new** attempts without rewriting historical attempts. Before the
   initial POST, atomically and durably claim the first transmission in the
   existing `EmissionAttemptJournal`; do not add a second retry journal.
   #429 performs its final check of the actual payload at the last mutable
   boundary. A blocked assessment causes **zero POSTs** and never releases
   an allocated number for automatic reuse.
5. Journal states remain distinct: `pending` before first POST,
   `authorized` only with a persisted valid receipt, `rejected` only
   after a confirmed official response, `ambiguous` after an uncertain
   response or persistence failure, and `technical_failure` only where a
   local pre-POST failure is confirmed. Persist receipt and authorized
   transition in one transaction. Do not demote an authorized receipt.
6. On timeout, lost response, crash, 5xx of uncertain provenance, or a
   missing receipt/access key, **query the same immutable DPS read-only**;
   do not send another POST. A repeated HTTP/job request first reconciles
   the journal/reservation rather than treating a new request as a new
   allocation. Historical access for recovery must use #421's explicit
   read-only authorization, not a permissive reservation bypass.
7. An independently initiated reissue after cancellation or replacement of
   an authorized NFS-e uses a distinct operation key and new reservation;
   original NFS-e numbers, XML, event links and archived artifacts remain
   immutable. Do not reinterpret a rejection as permission to recycle a
   number: the authoritative rejected-number and sequence-gap rules remain
   an explicit normative gate.

These steps require a deliberate transaction boundary between durable
reservation and first-POST claim. It is **unsafe** to reserve a number, perform
an unguarded POST, and create the journal record only afterward.

## Deployment and external coordination gate

The current unique indexes and `SELECT ... FOR UPDATE` protect only
connections sharing the **same** MySQL data store. Two independent database
installations may each allocate the same fiscal identity even if both write
identical `coordination_reference` strings. There is no cross-installation
coordinator or verified exclusive-series registry in this kernel.

As of #476, the allocator also requires an independently supplied
`DpsSeriesCoordinationVerifier` for each reservation call. Its default
construction has **no** verifier, so a locally active row, a nonempty
`coordination_reference`, or a copied database cannot allocate a new DPS.
A reservation replay through the allocator is also denied without current
proof; recovery of an already transmitted identity must be **read-only**
through the existing authorized journal, never treated as a new first POST.
The feature-test verifier is synthetic and must not be deployed.

A production verifier must be independent of the local claims and bind its
assertion to the *exact* issuer municipality, registration type and value,
environment, normalized series, coordination reference and high-water mark.
Its durable evidence must demonstrate:
- all allocating installations really use the same atomic counter **or**
  each disjoint series has one enforceable exclusive owner;
- no other live/stale process, restored backup or external issuer can use the
  same series while that owner allocates;
- the initial number exceeds previously reserved/transmitted identifiers,
  including numbers not locally recorded, with no automatic rejected-number
  recycling;
- revocation/fencing and loss of authority fail closed before activation
  and before the final first-POST boundary.

**A check-only verifier is not an implementation of distributed exclusion.**
In particular, a finite-lived assertion followed by an unguarded local
increment has a time-of-check/time-of-use race. A future provider must
demonstrate an atomic allocation/fencing transaction and durable ownership
under split brain, crash, rotation and restore. Merely injecting a verifier
that returns normally, or testing two connections to one database, does
**not** satisfy this deployment gate. No such production provider is shipped
here.

The MySQL regression test `testTwoIsolatedMysqlDatabasesCanAllocateIdenticalNationalDpsIdentity`
reproduces the counterexample: independent schemas with identical
registration/series/reference both successfully commit local number 1.
This establishes the need for external coordination; it is not a test of
coordinator correctness and never calls SEFIN.

Before enabling a series, #417/#421 must require durable evidence of one of:
a genuinely shared atomic allocation store used by **all** installations for
that issuer/environment/series, or externally enforceable exclusive
non-overlapping series ownership. The evidence must cover an external issuer,
restore/rollback, a second Akaunting company and stale processes. Without
this proof, **production series activation and first POST must stay blocked**.
Do not create a standalone microservice without evidence of necessity.

The XSD snapshot documents 45-character `TSIdDPS` with issuing municipality,
federal registration type/registration, series and number; it is not scoped
by Akaunting `company_id`. `TSNumDPS` is positive 1–15 decimal digits.
The series normalization used by the kernel is a local convention, not
proof of a nationally recommended default. The current February XSD
snapshot has a series-pattern inconsistency and an identifier digit pattern
that must be reconciled with any deployment supporting alphanumeric CNPJ.
Verify official production/restricted bundles and actual endpoint deployment
before making these capabilities operational.

## Consumer-driven acceptance tests for #421 and #429

- Same persisted operation requested via manual, send/listener, automatic,
  grouped/batch and job workers returns one reservation and produces at most
  one first POST to **synthetic fake SEFIN**, regardless of request order.
- Distinct fiscal operations allocate distinct numbers on two real MySQL
  connections. Different fiscal issuers can use the same series/number;
  separate Akaunting tenants representing the same issuer share the local
  counter but cannot read/modify one another's records or certificates.
- A forged tenant, mismatched invoice, wrong profile, unauthorized
  environment/series, removed profile, unresolved job context, Vault outage
  and unproven cross-installation coordination fail without any POST or
  cross-tenant disclosure.
- A crash before reservation, after reservation commit, during first-POST
  claim or after remote success before receipt persistence cannot generate
  a fresh reservation/second POST; only read-only DPS reconciliation is
  permitted after uncertain transmission.
- #429 blocks objectively invalid fiscal payloads at the last boundary,
  including on dequeued jobs; recovery and already authorized operations
  do not run a new first-POST path. A fake SEFIN accepting malformed tax
  fields must not override legal preflight.
- Reissue, cancellation and replacement preserve original authorized
  receipt/XML, reference the correct original fiscal operation and never
  recycle a DPS without documented normative permission.
- Confirm runtime XSD version, limits, counter overflow, idempotency,
  suspended series, transaction rollback and authorization in PHPUnit,
  Akaunting Feature, MySQL, relevant Playwright and final-HEAD CI.
  Operational restricted-environment validation, if authorized, is a
  separate gate; synthetic tests do not establish it.

## Ownership and remaining decisions

#417 provisions the company profile and safely approves series ownership.
#421 provides the authoritative context, reservation adapter and all
origin-specific integrations. #429 owns the last mutable fiscal preflight.
#423 retains the distributed coordination, normative reuse/gap policy,
integrated verification and explicit downstream handoff. Update the
per-company operational instructions in `docs/operacao-e-configuracao.md`
only when the series activation path is actually delivered.
