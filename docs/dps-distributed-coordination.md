<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DPS numbering in one multi-company Akaunting installation

## Supported topology

**One Akaunting installation, one MySQL 8+ database, multiple native companies.**
Each company keeps its own fiscal profile, certificate, invoices and receipts.
The sequence is keyed by the **national fiscal issuer** (issuing municipality,
federal registration type and registration), environment and five-digit DPS
series, *not* by the Akaunting company ID. Two company records representing the
same taxpayer cannot obtain the same fiscal number. Distinct taxpayers may use
the same series and decimal number without conflict.

There is **no second Akaunting installation, database, dedicated MySQL server
or numbering microservice**. The legacy design for independent installations
sharing a fiscal series is not part of this deployment contract.

The existing `nfse_dps_series` and `nfse_dps_reservations` tables retain the
invoice-side view of fiscal allocations. The same Akaunting database also
holds three authority tables:

- `nfse_dps_coordination_series`: fiscal scope, independently attested
  starting number, status and monotonic high-water.
- `nfse_dps_coordination_members`: approved application-instance ID and
  positive fencing generation.
- `nfse_dps_coordination_allocations`: immutable operation-to-number binding
  and irreversible first-transmission claim with payload fingerprint.

The normal, versioned Laravel migration creates these tables **inactive**
within the Akaunting schema. The SQL files in `docs/sql` are reference/legacy
material, not an instruction to create an external database.

## Why two database connections are still necessary

`ConfiguredDpsCoordinator` reads the native Akaunting MySQL connection
configuration. It opens a **second PDO connection to that exact same schema**;
there is no arbitrary external DSN. This is intentional, not another database.

The first transaction locks and commits the authority's number/operation
allocation. The invoice-side transaction then records its reservation. If
the second transaction rolls back, the authority allocation remains consumed:
a later attempt must reconcile it read-only, not recreate a first POST.
`EmissionAttemptJournal` commits `pending` before issuing an irreversible
claim in the authority transaction. After that claim commits, only the
original DPS may be queried for recovery. No second POST is authorized after
timeout, crash, 5xx, uncertain persistence or an already claimed operation.
Rejected/abandoned numbers are never recycled automatically.

The authority checks the current installation membership and generation
inside the same transaction as its allocation/claim. Series and membership
status changes are administrative operations, never implied by ordinary
application startup or the presence of matching text references.

## Provisioning and activation

1. Back up and verify the actual Akaunting MySQL database, application key and
   certificate/OpenBao configuration. Drain emission workers before changing
   an active series or migration. Apply the module's normal Laravel
   migrations; confirm all three authority tables exist in the **same**
   schema. Database migrations do **not** create or activate fiscal series.
2. Establish exclusive series ownership for each taxpayer and environment.
   The National Web Portal uses a different channel series from an API
   emitter. Existing issued NFS-e records, XML and DANFSe must never be
   renumbered. Historic restricted-environment transmissions also count,
   including rejected and ambiguous attempts.
3. Reconcile the **maximum ever consumed** DPS number per fiscal scope using
   existing attempts, saved official responses and **read-only** SEFIN DPS
   queries. Record independent evidence and its SHA-256 digest. A successful
   query of one DPS is not evidence that all higher numbers are unused.
   Do not assume a fresh series starts at zero without supporting evidence.
4. Provision the matching authority series with a cryptographically random
   64-character hexadecimal reference, the attested floor and SHA-256
   evidence, high-water equal to or above the floor, and status `inactive`.
   Provision one installation UUID and generation with status `inactive`.
   Configure a matching `nfse_dps_series` row with
   `coordination_mode=shared_database` (retained as a stable internal
   identifier), reference and high-water, also `inactive`. The mode name
   refers to the shared logical authority, **not** an external DB.
5. Store the stable installation UUID and positive generation in deployment
   configuration outside DB backups:
   `NFSE_DPS_INSTALLATION_ID` and
   `NFSE_DPS_INSTALLATION_GENERATION`. The normal application MySQL
   `host`/`port`/`database`/`username`/`password` are reused; the
   obsolete `NFSE_DPS_COORDINATOR_DSN` is **not used**. Optional
   `NFSE_DPS_COORDINATOR_USER` and
   `NFSE_DPS_COORDINATOR_PASSWORD` may specify *both* credentials for a
   restricted user of the **same schema**. Do not point them at another DB.
6. Restrict the coordinator user to SELECT and column-level
   UPDATE(`last_number`) on the authority series; SELECT on members;
   SELECT/INSERT and column-level UPDATE of first-POST claim fields on
   allocations. No DDL/DELETE/series activation/membership mutation for
   application workers. Provisioning credentials must remain administrative.
7. With all checks satisfied, enable exactly the intended restricted series
   and member, then the matching local series in a controlled transaction.
   Confirm the runtime uses MySQL, the approved installation generation,
   matching series/reference and the intended environment. Validate with
   synthetic tests first, then an explicitly authorized restricted-environment
   issuance, followed by read-only official recovery and persisted receipt
   checks. Keep production issuance disabled until independently authorized.

A missing configuration, inactive series, revoked generation, mismatched
reference, unknown historical starting number or unavailable MySQL connection
must prevent **new** transmissions. Merely creating a profile is not issuance
authorization.

## Backups and full-database restores

With one database, both the invoice journal and authoritative ledger can be
restored to an older point in time **together**. A database alone cannot
reliably detect that its own more recent records have been erased. Unlike the
former separate-database proposal, this design does **not** provide an
independent automatic rollback-proof high-water store.

The operational recovery rule is mandatory:

1. **Stop/disable all first-POST paths and drain workers before restoring.**
2. Preserve off-database evidence of the previous highest allocation,
   first-POST claims, authorized/rejected/ambiguous DPS and last known
   installation generation (for example, durable backup logs and SEFIN
   consultations). Back up the fiscal authority in a way that supports
   point-in-time recovery; do not silently restore an old snapshot.
3. After restore, invalidate the old installation generation and keep
   local and authority series **inactive** until the SEFIN history and
   off-database evidence have been reconciled. Never infer permission from
   missing journal rows, a matching application UUID or a 404 to a single
   DPS query.
4. If any transmitted allocation is absent from the restored database,
   reconstruct evidence under an audited exceptional process or advance to
   a verified noncolliding series after independent fiscal review. Never
   silently reuse the previous number or recreate a first POST.
5. Only a separately authorized operator may activate a new generation,
   reconciling the authority floor, local high-water and status first.

A restore performed without this process can defeat monotonicity and is
unsupported. Database/application backups must preserve the APP_KEY and
certificate references; do not put certificates, passwords or issued XML in
the DPS allocation tables.

## Verification

- MySQL migration suite: duplicate national identifiers, DB-level unique
  keys, foreign keys, rollback protection and schema idempotency.
- Real MySQL coordinator suite: competing PDO transactions, issuer isolation,
  same-CNPJ multi-company operation keys, replay after a partial commit,
  generation revocation, least-privilege grants and claim-at-most-once.
- Feature suite with fake fiscal transport: HTTP, manual, automatic, groups,
  batches, substitutions and recovery through one `EmissionAttemptJournal`.
- Restricted SEFIN testing is a separate operation: it must never use
  fabricated client credentials or production customer documents.

The coordinator cannot control an external issuer using the same DPS series
and does not replace official specification checks, taxpayer authorization
or operational series exclusivity.
