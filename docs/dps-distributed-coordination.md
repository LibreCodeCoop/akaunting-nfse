<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Coordinating DPS numbers across independent Akaunting installations

Status: **coordinator mechanics implemented; production issuance blocked**.
Scope: #423. Does not integrate the emitters or the last first-POST preflight
owned by #429. Does not create another fiscal journal or retry subsystem.

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

**No provider is registered automatically.** The optional dependency in
`DpsReservationService` defaults to null and fails closed. A boolean check,
a matching text reference, a fake verifier or SQL locks within only one
Akaunting installation cannot enable this coordinator.

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
   restored Akaunting database backups** and access to the *same* configured
   Laravel central-MySQL connection, e.g. use
   `DB::connection('nfse_dps_coordinator')->getPdo()` only after the operator
   installs that named connection using existing Akaunting deployment config.
   Pass it explicitly to `SharedMysqlDpsSeriesCoordinator` with the UUID
   and generation. No automatic binding and no connection from user input.
   The locally provisioned, active `nfse_dps_series` row must match the
   global fiscal identity, `coordination_mode=shared_database` and
   reference; it **does not itself constitute proof**. Provision/install
   adapters only in #421/#429 after the first-POST claim is implemented.
7. Use least-privilege MySQL credentials: allocation workers need SELECT and column-level `UPDATE(last_number)` on central
   series; SELECT only on members; SELECT/INSERT on allocations. Shared
   locking reads on member/allocation rows avoid broad UPDATE privileges.
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
attested historical maximum is true. The current module also has no final
first-POST fencing/claim wired into #429. A process that reserved a number
before revocation could otherwise transmit after revocation. Keep **all
production first POSTs and new series activation blocked** until the
operational exclusive-series attestation, final transmission gate, official
rejection/gap policy and integrated restricted-environment verification exist.

## Contract to #421 / #429

The planned authorized integration must resolve the persisted invoice's
company/profile/certificate, its immutable `DpsFiscalIssuer`, environment
and approved series, stable `DpsOperationKey`, and a concrete coordinator
bound to the registered installation. Allocate before building the DPS,
then durably attach the local reservation to the existing attempt journal.
The final, **irreversible** first-POST claim must check current central
authority/fencing and be coordinated with the existing journal: it is NOT a
fresh reservation, a second journal or an expiring lease that licenses
retransmission. Until that contract is delivered and tested, do not bind
this class in the production service provider.

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
