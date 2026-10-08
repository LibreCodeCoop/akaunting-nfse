<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# NFS-e issuance attempt provenance (#375)

## Source of truth and existing state

* `nfse_receipts` and `nfse_receipt_payloads`: **authorized fiscal documents**, access key, XML, artifacts and cancellation/substitution history. No rejected or speculative receipts are created.
* `nfse_fiscal_group_identities`: stable series `00002` + DPS number per invoice/group; ordinary DPS uses series `00001`. A cancelled receipt's id also deterministically identifies a **new** reemission DPS number (`9` plus 14 decimal digits).
* `nfse_bulk_emission_runs` / `nfse_bulk_emission_units`: operator selection, queue progress, latest unit error, final status and receipt link; not a durable per-POST error history.
* `ProcessBulkEmissionUnit` uses one worker attempt, native Akaunting company context and no automatic fiscal retry. `RecoverInvoiceEmission` performs **read-only** DPS/access-key recovery. WebDAV/DANFSE/email run after the fiscal receipt is persisted.

The missing historical data lives in `nfse_emission_attempts`, a diagnostic record rather than a new fiscal ledger. An attempt is uniquely numbered per company, environment and immutable DPS identifier. Each row records invoice, optional group, environment, DPS fingerprint, issuer municipality, national six-digit code, optional municipal three-digit complement, nine-digit combined service code, effective DPS competence, origin, timestamps, result, structured official code/message, HTTP status and an optional foreign key to the existing authorized receipt. A fingerprint is a SHA-256 of the DTO; full fiscal payloads, certificates, customer contact details and XML are **not** copied into the attempt table.

The `municipio_ibge` field records **the municipality in the emitted DPS**, not a legal determination of the ISS-incidence municipality. For #374/#376 integration, match `company_id`, `environment`, `dps_identifier`, `competence_date`, municipality and full service code; a historic E0312 is evidence of a rejection of that exact request, **never a general denial** for another taxpayer, municipality, invoice, competence or regime. The independent municipal evaluator contract remains advisory until #376 authenticates and binds official results.

## Lifecycle and recovery

1. Resolve/allocate the deterministic DPS identity. Insert `pending` **in its own committed transaction before POST**. Invoice row locking serializes concurrent operation allocation.
2. A structured SEFIN *issuance* response (400/422 with a recognized code and description) is `rejected`; E0312 and a sanitized/bounded official message are stored, but no receipt exists. A subsequent, deliberately initiated rejected operation gets a new attempt sequence.
3. A gateway 5xx, unstructured/non-issuance HTTP error, timeout, failed response parsing or uncertain read is `ambiguous`, not `rejected`. The first POST uses the existing `IssueInvoiceNfse` recovery contract. Any subsequent call for a pending/ambiguous DPS is **read-only recovery only**; unresolved recovery raises an error without ever repeating the POST.
4. Definite local pre-POST exceptions (e.g., certificate/preflight) become `technical_failure`.
5. On authorization or successful DPS recovery, store the existing fiscal receipt/XML and link the attempt as `authorized` **in one database transaction**. If persistence fails, rollback the link/receipt and mark the attempt ambiguous so recovery remains possible. Repeated authorization reuses the same receipt.
6. Post-emission Redis, WebDAV, DANFSE, mail and queue errors do **not** change the fiscal attempt from `authorized`. A rejected/blocked bulk unit remains terminal; an unconfirmed read-only issue is `retryable_read_error` and may be reconciled manually. No new worker/retry protocol is added.

Because a crash can occur after the row is committed but before the POST reaches SEFIN, a pending attempt deliberately fails closed on the next call: it may remain unresolved even if the service never received the request. Operators must reconcile externally; guessing from a 404/timeout to re-POST would risk duplicate fiscal effects. Existing, pre-migration receipts are not backfilled with fictitious attempts.

## Tenant access, secrets and retention

* Attempt creation resolves `company_id` from the underlying Akaunting document and rejects a different active company. Diagnostic queries check the current company AND the invoice tenant. Do not query this model unscoped from customer-facing controllers.
* Only authenticated users with `read-sales-invoices` may access the read-only, tenant-scoped diagnostic endpoint. It returns bounded diagnostic values, not the raw SEFIN payload or authorized XML.
* All upstream exception bodies, full XML, PFX/PEM files, secret-store contents and authentication tokens are excluded. Diagnostic messages redact common CNPJ/CPF, email, token and structured content patterns. Administrators must still treat DPS ID, competence and messages as sensitive fiscal metadata and restrict backups/exports accordingly.
* Retain attempt metadata as long as the related fiscal document and necessary audit trail under the organization's fiscal/LGPD retention policy; do not prune independently of invoice retention without legal approval. Invoice deletion cascades attempts; receipt deletion nulls the optional correlation. Backups, database encryption and least-privilege database access follow the fiscal ledger policy.
* Migration `2026_10_08_000002` creates the additive table and indexes (including the per-DPS uniqueness constraint). There is no fiscal data backfill. Rollback drops only the attempt history and must be authorized as a destructive audit operation.

## Validation

Tests cover E0312, other structured rejection, generic 5xx and query errors, transport timeout, authorization recovery, duplicate job/reconciliation, atomic receipt links, cross-company and unauthorized reads, grouped and automatic emission, and persistence of authorization independent of post-emission errors. Existing Playwright/UI coverage is not duplicated.
