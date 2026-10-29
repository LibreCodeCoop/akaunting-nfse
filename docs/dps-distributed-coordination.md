<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Coordinating DPS numbers across independent Akaunting installations

Status: **central reservation and first-POST claim integrated through #515/#516;
operational series activation and real fiscal issuance remain blocked until
exclusive ownership, historical reconciliation and restricted validation**.
Scope: #423, integrated with the #421 company context and #429 municipal
preflight. The existing journal remains the sole emission attempt ledger.

## Decision and guarantees

Choose **A — one transactionally shared MySQL 8+ primary**, without a
microservice. All installations authorized to allocate a specific immutable
fiscal series must transact against the same durable InnoDB authority. Their
Akaunting databases remain independent.

The global series key is **(target environment, issuing municipality,
registration type, normalized federal registration, five-digit series)**.
Neither `company_id`, invoice IDs, browser sessions nor certificate paths
belong to this key. Production (1) and restricted (2) are independent. A
globally unique, stable installation UUID (stored OUTSIDE the local database)
plus the existing 64-character `DpsOperationKey` binds each logical request
to one immutable number. Two Akaunting companies for the same issuer use
the same series counter, but are still independently authorized locally.

`SharedMysqlDpsSeriesCoordinator::allocate()` locks the global series and
installation membership, verifies the exact coordination reference, active
status, immutable identity and fencing generation, then increments the global
counter and inserts the operation's allocation **in the same transaction**.
InnoDB unique indexes independently reject duplicate national DPS numbers,
and prevent one installation operation from being rebound. `fresh=false`
signals a global allocation that survived while the local record is absent;
the caller **must not** re-create local state or send a new first POST.
`assertExisting()` is read-only, verifies the central mapping and refuses
fenced members. This does not require SEFIN transport.

## Irreversible first-POST claim in the central authority

`claimFirstTransmission()` acquires the exact central fiscal-series and
installation-membership locks, checks the immutable operation/number mapping,
then records `first_post_claimed_at`, the fencing generation and the payload
SHA-256 in the existing allocation row. A second claim fails permanently.
The claim commits **before** any fiscal HTTP POST. A timeout or lost commit
acknowledgment must be treated as potentially claimed; never retry the claim
with the expectation of obtaining another POST permission. Reconcile the same
DPS read-only instead. No expiring lease or second retry journal is introduced.

For a fresh shared database, use the updated
[`dps-coordination-schema.sql`](sql/dps-coordination-schema.sql). For an
existing coordinator, run the one-time
[`dps-first-post-upgrade.sql`](sql/dps-first-post-upgrade.sql) only after a
backup. **Before enabling emission**, identify and reconcile all previously
transmitted allocations: the upgrade cannot reconstruct earlier first-POST
claims. An allocation whose historical transmission state is unknown must
remain blocked; do not interpret a new nullable claim field as permission.

The coordinator blocks revoked generations at claim time. It cannot recall a
POST already initiated before revocation, so workers must be drained and
in-flight results reconciled. A central MySQL split-brain or rollback of the
allocation ledger invalidates the guarantee and requires suspension.

The local Laravel reservation transaction uses **one attempt**, not a
blind deadlock retry: the central transaction can commit before the local
transaction fails. Any retry of the same operation then finds an existing
central allocation and deliberately requires read-only recovery; it must
not reconstruct the local reservation or initiate a fresh fiscal POST.

The separate local `nfse_dps_series.last_number` is now only an observed
high-water mark. It may jump due to allocations by peer installations.
The local `nfse_dps_reservations` ledger and `EmissionAttemptJournal`
remain the sources for local operation history and emission recovery. Global
allocation history is append-only: even rejected, abandoned or ambiguous
numbers are not automatically recycled.

**No shared MySQL authority is provisioned automatically.**
`Providers/Main.php` binds `DpsIssuanceIdentityContract` to the
production issuance adapter, but without configured central MySQL connection,
installation membership and approved fiscal series it fails closed. A
matching text reference or local SQL lock cannot enable issuance.

## Provisioning and operating boundary

1. Operate a *single-writer, transactionally durable* MySQL primary shared by
   all participating installations; do not point participants at writable
   replicas, independent schemas with identical names or asynchronously
   promoted primaries. Its durable allocation ledger must **not** be restored
   to an earlier committed state. Use PITR/replication with a verified
   non-rollback high-water/operation history; suspend all allocations during
   disaster recovery or leader migration until the ledger is reconciled.
2. Apply [the explicit SQL schema](sql/dps-coordination-schema.sql) to the
   central store only. It performs no inserts or activation. No deployment
   migration adds it to Akaunting or silently provisions a series.
3. With verified fiscal authorization, decide the intended series and collect
   independent evidence that no other service, manual emitter or installation
   can use that **same** series. A coordinator only controls *participants*;
   SEFIN does not delegate universal series ownership to this SQL database.
   Assign a nonoverlapping series or prohibit every uncoordinated issuer;
   record the operational commitment and revocation process.
4. Reconcile all earlier used/reserved/transmitted DPS numbers for that issuer,
   environment and series, including gaps, pending results, other emitters
   and homologation. Record the evidence's SHA-256 and **attested_floor** as
   the maximum ever consumed. Set `last_number` to that floor, not to zero
   unless independently established. Neither an evidence hash nor a local
   counter alone proves the factual correctness of that floor.
5. In a separately authorized transaction, provision the exact global
   `nfse_dps_coordination_series` row with a random 64-hex reference, the
   floor/evidence digest and status `active` only after checks. Provision
   `nfse_dps_coordination_members` for each approved installation UUID with
   its current positive `generation` and active status. Duplicate fiscal
   series identities are rejected by SQL. Keep the evidence and provisioning
   approvals outside the fiscal logs; never store PFX, tokens or XML here.
6. Give each installation a durable UUID and fencing generation **outside
   restored Akaunting database backups**. Configure the same MySQL primary via
   `NFSE_DPS_COORDINATOR_DSN`, `NFSE_DPS_COORDINATOR_USER`,
   `NFSE_DPS_COORDINATOR_PASSWORD`, `NFSE_DPS_INSTALLATION_ID`
   and `NFSE_DPS_INSTALLATION_GENERATION`. The existing
   `ConfiguredDpsCoordinator` creates its PDO connection and has no local
   fallback. The local active `nfse_dps_series` row must match the global
   identity, `coordination_mode=shared_database` and reference; it
   **does not itself constitute proof**. The adapter and claim are integrated,
   but activating a fiscal series still needs independent evidence.
7. Use least-privilege MySQL credentials: allocation workers need SELECT and column-level `UPDATE(last_number)` on central
   series; SELECT only on members; SELECT/INSERT and column-scoped UPDATE
   (`first_post_claimed_at`, `claimed_generation`, `first_post_fingerprint`)
   on allocations. Shared locking reads on member/allocation rows avoid broad
   UPDATE privileges.
   No DELETE, DROP, member UPDATE, series status/evidence UPDATE or DDL.
   Validate these grants using the least-privilege MySQL integration test. Separate administrative credentials for
   provisioning, revocation and exceptional reconstruction. Restrict
   network access and encrypt transport. Monitor ledger consistency and
   lost connectivity without logging credentials or taxpayer PII.

A member is revoked by an authorized administrator updating its generation
and/or status **in the same global primary**. The row lock makes new
allocations using the stale generation fail after revocation commits.
Installation takeovers need a newly distributed generation and old process
credentials must be revoked separately. Do not reuse UUID+generation for an
untrusted cloned installation.

## Failure/attack matrix (no SEFIN transmission)

| Scenario | Result / safe recovery |
| --- | --- |
| Two local databases, same issuer and series | Different global allocations, serialized by central InnoDB row lock; central unique index |
| Two Akaunting companies representing same issuer | Distinct operation keys and numbers; tenant permission still enforced locally |
| Different issuer or target environment | Independent globally unique fiscal scope; same decimal number is allowed |
| Repeat one operation | Same central allocation; locally persisted replay must match read-only; no new number |
| Crash after global commit, before local commit | Globally allocated number remains consumed; absent local history causes **block**, operator read-only reconciliation |
| Restored stale local DB | Central high-water cannot roll back; missing local record with known operation is blocked, never silently resent. Local high-water may safely jump forward to the authoritative number |
| Revoked/stale process | Generation/status checked inside transaction; new allocation blocked |
| Network outage, lock timeout or split primary | Failed coordination means no new allocation. Split-brain CENTRAL primary invalidates safety assumptions and requires operational intervention |
| Rejection or ambiguous response | Keep allocated number, no automatic recycle or second POST; reconcile immutable DPS via existing journal |
| Read-only recovery | No allocation or new POST. Requires separate authorized journal/reconciliation flow |
| Authority table/floor missing or reference mismatch | Fail closed without touching local or global counter |

**Hard boundary:** this coordinator cannot prevent a nonparticipating
external issuer from using the same series, nor prove that a manually
attested historical maximum is true. #515/#516 added an irreversible
first-transmission claim in the shared MySQL ledger. A claim already committed
before installation revocation cannot recall an in-flight fiscal POST. Keep
**real first POSTs and series activation disabled** until verified exclusive
series ownership, official sequencing policy review, existing-ledger
reconciliation and restricted-environment verification are complete.

## Direct fiscal transport boundary (mandatory rollout gate)

The supported manual, automatic, grouped, batch, reissue and substitution
callers now pass through `EmissionAttemptJournal::issue()`, which binds the
local immutable reservation, runs municipal preflight and commits the global
first-POST claim before invoking transport. `IssueInvoiceNfse::issue()`
remains a low-level transport helper that can call `emit()` directly; it is
not an authorized public issuance entrypoint. New callers must never invoke
that helper outside the journal. Tests must cover these actual call paths.

Neither the PHP code nor the shared ledger attests exclusive ownership of a
series against outside emitters. The safe default is to reject issuance when
the coordinator or authorized profile is unavailable. Do not activate the
first real fiscal POST merely because #515/#516 merged.

The SEFIN API does not validate a local fencing token. Revocation can
prevent *new allocations and claims* but cannot reliably recall an already
authorized in-flight HTTP request. Operators must drain/terminate old
workers during revocation and treat any in-flight result as ambiguous until
read-only reconciliation. Do not claim hard real-time transmission revocation.

## Integrated contract with #421 / #429

`DpsIssuanceIdentity` resolves the persisted invoice owner and approved
fiscal profile, validates the immutable issuer/environment/series, derives
`DpsOperationKey` and reserves an authoritative number. The existing
`EmissionAttemptJournal` persists the local reservation and rechecks
municipal preflight before the **irreversible** central first-POST claim.
The claim checks current authority and fencing; it is neither a new
reservation nor an expiring retry lease. The provider binds the adapter,
but real issuance fails closed without a configured coordinator and
independently verified series activation.

`nfse-php` remains responsible for official XML and identifier formation;
the pinned module revision is
`2b52325f253db74a0da2ee18a3e1b27a7bfbe31f`. Existing
`DpsFiscalIssuer` retains the municipality/CPF-or-CNPJ representation.
Verify current official XSDs per environment before enabling alphanumeric
registration or selecting a default series. The official DPS description
requires increasing sequence; current portal's production/restricted XSD
bundles differ, and no reviewed official annex in this work authorizes
automatic reuse of a rejected DPS.

## Verification

Run the existing feature suite (synthetic coordinator only) and the
`MySQL Migrations` suite against a dedicated `*_migration_tests` MySQL
database. The latter creates and cleans test-only peer/central databases,
uses distinct PDO connections and never calls the SEFIN. Verify negative
tests for missing/fenced authority, network failure, stale local backup,
central/local partial commit, idempotency, environment/issuer separation,
number ceiling and nonrecycling. CI success is not deployment evidence.
