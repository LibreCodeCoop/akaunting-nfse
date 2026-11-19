<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0003: DPS identity, reservation and fiscal numbering authority

Status: Accepted — supported multi-company architecture.

## Context and boundaries

A DPS number belongs to the **fiscal issuer**, not to an Akaunting
invoice or its `company_id`. The national identity combines the issuing
municipality, normalized federal registration and registration type, fiscal
environment, series and sequential number. The official
[DPS guidance](https://www.gov.br/nfse/pt-br/saiba-mais/como-a-nfs-e-e-gerada/o-que-e-dps)
requires taxpayer-controlled, increasing numbering. The protocol and
environment-specific XSD constraints belong to
[nfse-php](https://github.com/LibreCodeCoop/nfse-php); published schemas
must not be confused with the versions actually enabled by SEFIN.

An Akaunting company is an **authorization and data-isolation boundary**.
Two authorized company records may represent the same fiscal issuer and must
share its numbering authority without sharing invoices, certificates or
fiscal artifacts. The commercial invoice, a reserved DPS, an emission
attempt and an authorized NFS-e receipt are distinct, durable identities.

## Decision

The supported deployment is **one Akaunting installation, one MySQL 8+
database and multiple native companies**. Each company owns its fiscal
profile, certificate, invoices and receipts. This project does not support
independent Akaunting installations allocating numbers for the same fiscal
series from separate databases.

Number allocation is keyed by issuing municipality, registration type,
normalized federal registration, target environment and approved
five-digit series, **not** by `company_id`. The reserved number is a
positive decimal of at most 15 digits. The ordinary invoice ID, group row
ID and receipt ID are not fiscal sequence authorities. Production and
restricted-production environments have independent sequences.

The versioned Laravel migration provides the authority tables
`nfse_dps_coordination_series`, `nfse_dps_coordination_members` and
`nfse_dps_coordination_allocations` in the **same Akaunting database**,
alongside the invoice-side `nfse_dps_series`,
`nfse_dps_reservations` and `nfse_emission_attempts`. Migration does
not provision, activate or validate ownership of a fiscal series.

`ConfiguredDpsCoordinator` derives the active Akaunting MySQL
configuration and opens a **second PDO connection to the same schema**.
This independent connection commits number allocation and the
irreversible first-transmission claim separately from the invoice-side
transaction. It is neither a second database nor an external numbering
service. `SharedMysqlDpsSeriesCoordinator` checks active series
ownership, installation membership, generation fencing and the immutable
operation key under MySQL locks. A matching textual reference or local
counter alone does not authorize issuance.

`DpsReservationService` binds each reservation to an authorized
company-owned invoice, fiscal issuer, environment, series and stable
logical operation key. An ordinary invoice issuance, each fiscal group,
reissuance after cancellation and substitution are distinct operations.
Repeated compatible requests to one operation reuse its reservation;
conflicting context is rejected. Caller origin (manual, automatic,
batch or job) is audit metadata rather than an idempotency key.
Company/profile authorization is required even when reusing a
reservation. No certificate, secret or full DPS XML is stored in the
authority ledger.

`DpsIssuanceIdentity` verifies the persisted fiscal context and reserved
identity. `EmissionAttemptJournal` persists the pending attempt,
re-evaluates the final fiscal preflight and coordinates the central
first-POST claim before calling the transport. A claim that may have
preceded a crash **cannot** be reset merely because no receipt was saved
or a lease elapsed. A timeout, ambiguous response, 5xx or partial commit
permits only read-only reconciliation of the *same DPS*, never a blind
second POST. An authorized receipt, its XML and links to cancellation or
replacement remain immutable. Definitive rejection does not itself
license reuse of the number; any supported corrected successor requires
a separately authorized operation and distinct reservation.

## Security and operational consequences

- Missing fiscal profile, unauthorized company context, inactive or
  unverified series, revoked generation, unavailable authority and
  conflicting reservation block a new fiscal transmission. A known
  number is not proof of legal or protocol eligibility.
- Allocation and claim are monotonic while the current authority
  remains intact. A second company representing the same issuer cannot
  allocate a duplicate number through this authority.
- The coordinator **cannot** detect an external emitter that does not use
  its authority or prevent an undisciplined full-database rollback.
  Exclusive series ownership and independently attested historical
  high-water evidence are prerequisites to activation.
- Restoring the sole database can roll back the financial and authority
  ledgers together. All first-POST paths must be disabled during restore;
  series and installation generation remain inactive until off-database
  evidence and read-only SEFIN history are reconciled and a new
  generation is deliberately authorized. A single negative DPS lookup
  does not establish that higher numbers were unused.
- Official rejection, sequence-gap and series-use rules are determined
  from authoritative, environment-specific norms. Unknown or disputed
  behavior fails closed; an E0312 response alone does not establish a
  numbering collision. No authorized fiscal document is renumbered to
  compensate for local uncertainty.

## Implementation and operations

The allocation, partial-commit, authorization and first-POST contracts
are described in [DPS reservation integration](../dps-reservation-integration.md).
Provisioning, least-privilege permissions, number adjustment, recovery
and the supported deployment topology are described in
[DPS coordination operations](../dps-distributed-coordination.md).
The [operational guide](../operacao-e-configuracao.md) describes fiscal
profile setup and emission gates. The existence of these mechanisms
does **not** mean a company's series is activated or that real fiscal
issuance has been authorized.

The decisive safety invariants are covered by the real-MySQL
coordinator tests, the reservation/attempt tests and fake-SEFIN
integration tests. Synthetic success is not evidence of official series
ownership, of a particular SEFIN environment's active schema or of
correct tax-law treatment.
