<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Native NFS-e API: first fiscal catalog

The module registers `Route::api('nfse', ...)` through the Akaunting
module route macro. The installed Core determines API prefix, domain,
authentication (Basic or OAuth Bearer when enabled), rate limiting, `read-api`
permission and active-company identification. Module routes additionally require
`read-nfse-settings`.

## Read-only national service catalog

- External client: `GET /{company_id}/{api_prefix}/nfse/national-services`
- Browser session: `GET /{company_id}/nfse/national-services`
- Query: optional `lc116` (four-digit service code), `q` (maximum 100 characters),
  and `limit` (integer from 1 through 500, default 50).
- Success: `200 application/json`, `{"data":[{"code":"...","description":"..."}]}`
  containing at most `limit` entries. Without search filters, the first
  `limit` official entries are returned. A one-character nonempty search without
  an LC code returns an empty array.
- Invalid LC code, non-scalar query, invalid limit or excessive query: `422
  application/json` with empty `data`.
- Access denial: Core-controlled 401 or 403 JSON with `Accept: application/json`.
- The API prefix and Core API version remain configured by Akaunting, rather
  than a new module-owned authentication or version scheme.

Both routes call the **same** controller, using `nfse-php`'s
`OfficialDomainCatalog`. The existing fiscal item assistant uses the
browser-session route; it does not handle Basic passwords or Bearer tokens.
The national-code selector loads its complete bounded catalog from
`GET /{company_id}/nfse/national-services?limit=500` after rendering the form;
Blade supplies only the previously selected code. Both the initial selector
and the on-demand assistant therefore consume the same JSON operation.
The two HTTP paths are deliberate security transports, not two domain APIs:
the API group is stateless, whereas the admin group includes session and CSRF.
Do not forward browser cookies into the native API group.

## Fiscal data in native Akaunting item and customer resources

The module extends the Core's existing item and contact API JSON resources via
`App\Events\Api\ResourceShowing`. It does not add another item/contact
CRUD endpoint. The extra `nfse_fiscal_profile` member is returned only when
the authenticated user has the native resource read permission
(`read-common-items` or `read-sales-customers`) **and** `read-nfse-settings`, and the
resource belongs to the active Akaunting company. It is omitted entirely
without the fiscal read permission, and is `null` when no profile exists.

- `GET /{api_prefix}/items/{id}?company_id={company_id}`: `nfse_fiscal_profile` contains
  `item_lista_servico`, `codigo_tributacao_nacional`,
  `codigo_tributacao_municipal`, `codigo_nbs` and `rtc_supply_category`.
- `GET /{api_prefix}/contacts/{id}?company_id={company_id}`: for customers, the member
  contains `municipal_registration` and `legal_name`; other contact types
  retain their native Core representation.

Core controls authentication, native item/contact ACL, tenant routing and CRUD
operations. The module's existing fiscal listeners persist item/customer
attributes within the native Core create/update jobs. Browser forms continue
using their existing authenticated native Core form routes; fiscal catalog
lookups use the session-protected JSON transport described above. No external
API credentials are placed in HTML or JavaScript.

No new fiscal mutation route is introduced by the catalog foundation. Future
fiscal mutations require operation-level ACL, tenant and object checks, CSRF
for session-authenticated writes, idempotency and negative integration tests.
The native Core API remains unchanged.

## Read-only emission attempt history

The existing fiscal attempt journal supports both HTTP transports using the same controller:

- External: `GET /{company_id}/{api_prefix}/nfse/invoices/{invoice_id}/emission-attempts`
- Browser: `GET /{company_id}/nfse/invoices/{invoice_id}/emission-attempts`

The native Core API requires its own authentication and `read-api` along with `read-sales-invoices`. The browser continues to use the admin session. Both responses contain up to 100 newest attempts for an invoice owned by the active company, without raw XML or full transmitted documents. A fiscal attempt is distinct from an issued NFS-e receipt and from the Akaunting commercial invoice.


## Fiscal tax associations and approximate-tax provenance (#502)

These contracts expose only classification of **existing** Akaunting taxes
and versioned estimated-tax evidence. They do not create accounting taxes,
calculate fiscal incidence, alter financial items or overwrite authorized XML.

| Operation | Native API suffix | Browser-session suffix | Permission |
| --- | --- | --- | --- |
| List tax identities, roles, and historical confirmations | `GET settings/tax-associations` | `GET settings/tax-associations/resources` | `read-nfse-settings` |
| Confirm a fiscal identity/role for a native tax | `POST settings/tax-associations` | `POST settings/tax-associations` | `update-nfse-settings` |
| Paginate estimated-tax source revisions | `GET settings/approximate-taxes` | `GET settings/approximate-taxes/resources` | `read-nfse-settings` |
| Append an estimated-tax evidence revision | `POST settings/approximate-taxes` | `POST settings/approximate-taxes` | `update-nfse-settings` |

Prefix native suffixes with `/{company_id}/{api_prefix}/nfse/` and browser
suffixes with `/{company_id}/nfse/`. Browser JSON POSTs require CSRF; native
API calls use Akaunting authentication and permission middleware.

The tax association listing returns `data.taxes`, `data.identities` and
`data.roles`. Each tax contains current native metadata and historical
`revisions`, including effective period, source, confirming operator, and
a structural `type_mismatch` warning. The warning is **not** a normative
tax-incidence decision. Confirmation bodies require the existing `tax_id`,
`fiscal_identity`, `fiscal_role`, `effective_from`, optional
`effective_until`, `source_reference` and `acknowledge=true`.
Exact repetitions return 200 with `data.already_confirmed=true` while
new confirmations return 201. Ownership is checked on every submission,
including retries; conflicting periods return 409.

Approximate-tax source queries return `data` and `meta` pagination
(`limit=1..100`, default 50) scoped to the active company, without
fabricating zeros for unknown sources. Submissions retain the current
validation rules for fiscal service code, competence range, source kind,
provenance, version and three percent values. New revisions return 201 with
the persisted ID; duplicated revisions or unsupported zero evidence return
422. Historical records are append-only. The administrative screens load
their tables and save confirmations over these JSON contracts, without using
HTML fragments as an integration protocol.

## Fiscal company profiles, certificates and shared infrastructure (#501)

All operations retain the Akaunting Core API authentication and user/tenant
authorization, with authenticated browser-session equivalents under
`/{company_id}/nfse/`. The browser must not send Basic or OAuth credentials
to a session route. The active company is resolved by the Core middleware;
profile operations additionally verify that the authenticated operator can
manage the explicitly selected Akaunting company, rather than assuming that
the `companyId` route parameter grants access.

| Native API suffix | Method | Permission | Behavior |
| --- | --- | --- | --- |
| `settings/company-profiles` | GET | `read-nfse-settings` | Eligible companies and safe profile metadata |
| `settings/company-profiles` | POST | `update-nfse-settings` | Create a profile with a PFX/P12 upload and validated fiscal parameters |
| `settings/company-profiles/{companyId}` | PATCH | `update-nfse-settings` | Partial settings update or guarded certificate rotation |
| `settings/company-profiles/{companyId}` | DELETE | `delete-nfse-settings` | Delete when safe; preserve historical fiscal documents |
| `settings/company-profiles/{companyId}/cleanup` | POST | `delete-nfse-settings` | Retry incomplete certificate cleanup |
| `certificates/inspect` | POST | `update-nfse-settings` | Inspect an uploaded PFX without persisting it |
| `settings/vault` | GET | `read-nfse-settings` | Safe global infrastructure status |
| `settings/vault` | PATCH | `update-nfse-settings` | Set infrastructure credentials and configuration |
| `settings/vault/diagnostics` | POST | `read-nfse-settings` | Operational readiness without returning secrets |

Prefix each native API suffix with
`/{company_id}/{api_prefix}/nfse/`, with the API prefix configured by Core.
The backend `CompanyProfileController` serves both HTTP transports; it never
returns PFX contents, private keys, Vault tokens, role IDs or secret IDs. A
malformed upload is rejected without persisting certificate material.
A profile's certificate can only be removed through the guarded profile
delete/cleanup flow. Legacy certificate routes remain intentionally disabled.

`GET settings/vault` returns `data.addr`, `data.mount`,
`data.auth_mode`, `data.ready` and `data.configuration_error`. It never
returns credential values, even when configuration is unreadable. Credential
values omitted or left empty in `PATCH settings/vault` retain the existing
semantics of `GlobalVaultSettings`; explicit clear flags are required to
remove a stored secret. A successful JSON update returns the safe status
representation. The browser settings screen loads this metadata from JSON
rather than embedding infrastructure configuration in HTML, and submits
the Vault form with a session-CSRF-protected JSON-accepting request.

Jobs and automatic fiscal flows resolve these same profiles and infrastructure
stores directly; no internal HTTP calls or alternative secrets store are
introduced.

## Competence closing and commercial-fiscal reconciliation (#503)

`GET /{company_id}/{api_prefix}/nfse/closing?competence=YYYY-MM`
uses Akaunting API credentials and the `read-sales-invoices` permission.
The administrative page uses the same `ClosingController::data`
contract through authenticated `GET /{company_id}/nfse/closing/data`,
never transmitting browser credentials to the external API.

Responses include `data.summary`, `data.reconciliation`,
`data.rows` (up to 50 entries per page), and
`data.commercial_review.rows` (up to 30 commercial documents per page),
with corresponding `meta` pagination. `report_page` selects the
fiscal report page; `page` selects the commercial review page.
`competence` uses the exact `YYYY-MM` calendar format. Invalid
competence or page parameters yield 422. The company identity comes
from authenticated Akaunting context and is applied by receipt ownership
and the commercial report; a caller cannot select another company by
passing an arbitrary ID.

The fiscal summary uses persisted authorized XML snapshots and preserves
cancelled/substituted states, unreconciled documents and tax provenance.
Commercial invoice records are **review candidates**, not evidence of a
taxable event or a statutory deadline. No tax rates are inferred. The
existing `GET /{company_id}/nfse/closing/export` remains a stable,
CSRF-safe CSV download based on the same report service; no financial or
fiscal state is modified by the JSON read.

The native `GET /{company_id}/{api_prefix}/nfse/closing/export?competence=YYYY-MM`
returns the same CSV stream under API authentication and read permission.

### Documentary IBS/CBS evidence (not a tax assessment)

The fiscal closing JSON also returns `data.ibs_cbs_evidence.summary`, with
the independent IBS and CBS totals **reported in stored authorized-XML
snapshots**, counts of reported, absent and invalid fields, duplicate sources,
missing snapshots and excluded inactive receipts. A missing amount is not
zero and a reported amount is not an established tax debt, credit, payment
or declaration.

The following company-scoped, read-only CSV endpoints export the underlying
document observations, including status and SHA-256 provenance:

- Native API: `GET /{company_id}/{api_prefix}/nfse/closing/ibs-cbs-evidence?competence=YYYY-MM`
- Browser session: `GET /{company_id}/nfse/closing/ibs-cbs-evidence?competence=YYYY-MM`

Both use the existing `read-sales-invoices` permission. The result never
includes another company's receipts and cannot trigger tax accounting
entries or official synchronization.

## Fiscal ledger and receipt-scoped authorized artifacts (#503)

`GET /{company_id}/{api_prefix}/nfse/ledger` uses Akaunting's native
API authentication plus `read-sales-invoices` and returns bounded
`data` / `meta` pagination of fiscal receipts owned by invoices in
the authenticated company. This does not duplicate the native
accounting invoice resource. Query parameters are:
`status` (`all`, `emitted`, `processing`, `cancelled`, `substituted`),
`search`, `from`/`to` (YYYY-MM-DD), `limit` (1..100, default 25),
and `page`. Invalid filters receive 422; no raw XML or artifact path
is embedded in list responses.

The administrative browser consumes the same
`FiscalLedgerApiController::index` JSON contract via
`GET /{company_id}/nfse/ledger/data` using its existing session;
the HTML route renders only filters, presentation and translated labels.

To download the **selected** authorized fiscal receipt, rather than a
possibly newer receipt of the same commercial invoice, clients use
`GET /{company_id}/{api_prefix}/nfse/receipts/{receipt}/artifacts/{artifact}`
where artifact is `xml` or `danfse`. The native endpoint reuses
`InvoiceController::downloadLedgerArtifact` under the same company and
invoice-read permission checks. Invalid artifact kinds return JSON 422,
unavailable artifacts return 409, and successful responses are raw
application XML/PDF streams, not JSON-encoded binary. The browser
continues to use its authenticated session download routes.
Neither request transmits NFS-e or changes document state.

## ADN distribution, review and import

ADN has one underlying synchronization/import implementation. External clients use
Akaunting's native API authentication and `read-api`; the administrative Web UI
uses its own authenticated session and CSRF protection. The two transports call
the same controller and domain services. They do not synchronize via internal
HTTP calls.

| Operation | Native API suffix | Browser-session suffix | Permission |
| --- | --- | --- | --- |
| Locally synchronized documents | `GET adn/documents` | `GET adn/documents` | `read-purchases-bills` |
| Review form selections | `GET adn/review-options` | `GET adn/review-options` | `read-purchases-bills` |
| Accounting preview | `GET adn/documents/{id}/preview` | `GET adn/documents/{id}/preview` | `read-purchases-bills` |
| ADN review decision history | `GET adn/documents/{id}/review-history` | `GET adn/documents/{id}/review-history` | `read-purchases-bills` |
| Live contributor distribution | `GET adn/distribution` | `GET adn/distribution` | `read-nfse-settings` |
| Events for an issued invoice | `GET invoices/{id}/adn-events` | `GET invoices/{id}/adn-events` | `read-sales-invoices` |
| Ignore received document | `POST adn/documents/{id}/ignore` | `POST adn/review/{id}/ignore` | `update-purchases-bills` |
| Import as draft bill | `POST adn/documents/{id}/import` | `POST adn/review/{id}/import` | `create-purchases-bills` |

Prefix native suffixes with `/{company_id}/{api_prefix}/nfse/` and browser
suffixes with `/{company_id}/nfse/`. The browser loads its pending review
list and selection choices via JSON, then submits review actions over its
session-protected transport, including CSRF.
The Blade view only provides presentation templates and translated labels;
it no longer embeds company fiscal documents or option lists.

The persisted document list supports `limit=1..100` (default 50),
`page`, `review_status`, `fiscal_role` and `include_preview=1`.
Results contain `data` and `meta` pagination information; raw XML is
deliberately absent. Opting into preview parses each bounded received or
intermediated document and returns `accounting_preview` (or null for an
invalid XML) without changing persisted state. `adn/review-options`
returns company-scoped minimal vendor/category/item choices and action
capabilities; the issuer CNPJ is visible only to a user authorized to
read NFS-e settings.

With `include_preview=1`, a persisted received ADN document that is not
ignored can also include `ibs_cbs_credit_candidate` alongside
`accounting_preview`. It contains the source hash, competence, environment,
and documentary amounts/statuses. Its `credit_eligibility` remains
`unverifiable`: it does not establish a credit to appropriate. Intermediated
and ignored records do not create a candidate. The standard list without
`include_preview=1` does not expose this additional field. Existing
`read-purchases-bills` permissions and active-company scoping apply.

`fiscal_role` distinguishes `emitted`, `received`, `intermediated`,
`event`, and `unknown`. Only `received` and `intermediated` documents
can be reviewed/imported as financial bills. Importing never creates a new
issued NFS-e.

The read-only preview parses a locally persisted authorized XML into
`data.accounting_preview`, without changing the ADN cursor, vendor,
financial bill, or review state. Invalid preview XML receives HTTP 422 and
foreign-company identifiers receive HTTP 404.

An import body supplies `contact_id` (or `create_vendor=1`),
`category_id`, `item_id`, `issued_at` and `due_at`. The result is
HTTP 201 for a new draft bill or 200 for a previously imported record,
with `data.bill_id`, `data.redirect_url`, `data.review_status` and
`data.already_imported`. Import and ignore decisions hold a row lock
inside a database transaction. Ignoring an already ignored document is
idempotent (200); ignoring an imported or otherwise non-pending document
returns 409. Invalid review mappings return 422. The import retains its
reference to the source ADN document, and the authorized fiscal XML is never
modified by a review action. Each accepted ignore/import decision and each
rejected/conflicting review decision is appended to the company-scoped
`nfse_adn_review_events` journal, without storing XML or secrets. Repeated
idempotent requests do not duplicate successful decision entries. The
`review-history` API returns newest-first audit entries with `data` and
`meta` pagination, `limit=1..100` (default 50), and the operator ID
and linked bill ID where applicable. Foreign-company document IDs return 404.

The live distribution query `GET adn/distribution?nsu=0&lote=1` calls the
existing company-certificate-backed ADN client. It does not persist
documents or advance synchronization state. Automatic sync still uses
`AdnSynchronizationService` via the existing jobs/commands. The existing
company-specific reconciliation is preserved; no alternate sync or
financial document writer is introduced.

## Controlled bulk emission and progress (#507)

The same `BulkEmissionOperations` service handles the administrative native
bulk action and externally authenticated clients; it reuses fiscal preflight,
`BulkEmissionPlanner`, `BulkEmissionRunRecorder`, and `BulkEmissionDispatcher`.
No fiscal transmission runs in the HTTP request. Queue workers own individual
issuance, journal updates and reconciliation.

- `POST /{company_id}/{api_prefix}/nfse/bulk/plan` requires
  `read-sales-invoices`; body `{"invoice_ids":[1,2]}` and maximum 100 distinct
  positive IDs. Returns `data.queued`, `data.blocked`,
  `data.already_issued`, and `data.not_found_or_forbidden` without
  side effects. An invoice from a foreign company is never inspected.
- `POST /{company_id}/{api_prefix}/nfse/bulk/dispatch` requires
  `update-sales-invoices`. On a newly queued run, HTTP 202 returns
  `data.run_id`, `dispatched`, `queued`, `blocked`,
  `already_issued`, and `reused=false`. Repeating an identical active
  run returns HTTP 200 with `reused=true`, without redispatch. When nothing
  is eligible, `run_id` is null and no job is created. A completed run
  is not automatically retried as an active run.
- `GET /{company_id}/{api_prefix}/nfse/bulk/runs` and
  `GET /{company_id}/{api_prefix}/nfse/bulk/runs/{run}` require
  `read-sales-invoices`. They return company-bound run/unit status, receipt
  reference, and a bounded 25-run page. Foreign run IDs return 404.

The session-authenticated administrative UI uses `GET
/{company_id}/nfse/bulk/runs` for progress, with the same JSON representation;
its shell does not embed fiscal data. Session JSON dispatch and plan endpoints
are available as `POST /{company_id}/nfse/bulk/dispatch-json` and
`POST /{company_id}/nfse/bulk/plan`. Browser requests retain CSRF protection;
no external API password is exposed to JavaScript. The Core's existing native
bulk selection action continues to submit through the same shared service.

## Invoice issuance preview and post-processing state

The fiscal invoice read resources use the same controller and JSON representation
for the administrator's session and independently authenticated Akaunting API
clients, without a separate implementation of readiness or fiscal rules:

- `GET /{company_id}/api/nfse/invoices/{invoice}/service-preview` exposes
  available fiscal groups, validation, proposed description and the existing
  non-secret email/taker defaults. The invoice must be a sales invoice owned
  by the authenticated company; requires `read-sales-invoices`.
- `GET /{company_id}/api/nfse/invoices/{invoice}/post-emission-status` exposes
  the latest receipt's post-processing stages (artifacts and email), poll hint
  and receipt-specific XML/PDF download URLs. Native API clients receive the
  native receipt artifact links, not session-only browser links.

The corresponding browser routes use the same service representation behind
Akaunting session/CSRF and the same read permission. A foreign invoice or
financial bill returns 404; a user lacking invoice access receives 403.
These GET operations are side-effect-free and do not imply issuance permission.
Transmission, replacement and cancellation are independent write contracts;
clients must not infer success from a preview or polling response.

## CBS assisted assessment (offline integration contract)

The CBS assisted assessment v2 protocol, incremental response reader and
in-memory conflict policy are documented in
[CBS assisted assessment](cbs-assisted-api.md). No official synchronization
API, scheduled consultation, authenticated source downloader, durable journal
or tax booking is enabled by this contract. Fiscal review and read-only
IBS/CBS documentary evidence must not be interpreted as appropriable credits
or a completed CBS declaration.

## Native invoice issuance command

`POST /{company_id}/api/nfse/invoices/{invoice}/emit` requires native Akaunting
API authentication, the active invoice-owning company and
`update-sales-invoices`. It invokes the **same issuance controller and domain
services** as the browser's existing `POST /{company_id}/nfse/invoices/{invoice}/emit`.
This preserves fiscal group selection (`nfse_fiscal_group_key`), the persisted
DPS reservation and attempt journal, ambiguous-response recovery, email options
and local fiscal validation. There is no second emitter and no HTTP timeout-based
retry loop. Clients must use only the existing supported fiscal form fields.

A confirmed operation returns HTTP 200 and JSON `success=true`, `status` and
`data.invoice_id`, `data.receipt_id`, plus native `links.status` and
`links.attempts`. For an unconfirmed operation, HTTP 409 returns
`success=false`, `status=not_confirmed` and the **same inspection links**;
this is **not** a statement that SEFIN rejected or authorized the DPS. Inspect
persisted attempts and receipt state before contemplating any retry. The
traditional browser request/response remains unchanged. Cross-company or
non-sales documents receive 404, missing authorization 403 and missing
native API authentication 401.

No new issuance numbering policy is introduced by this HTTP adapter. The
complete legal preflight and centralized DPS numbering requirements are
tracked independently, and the API does not make them implicitly complete.

## WebDAV artifact storage settings (#501)

The active company's optional authorized-XML and DANFSe storage configuration
uses the same controller through Akaunting session/CSRF and native API
transports:

- `GET /{company_id}/api/nfse/settings/artifacts` requires
  `read-nfse-settings`. Returns `data.configured`, the server hostname,
  `username_configured`, `password_configured`, path and filename templates,
  and the XML/PDF storage flags. The server never returns the full WebDAV
  URL, username, password, or upstream transport error messages.
- `PATCH /{company_id}/api/nfse/settings/artifacts` requires
  `update-nfse-settings`. Provide a JSON `nfse` object with optional
  `webdav_url`, `webdav_username`, `webdav_password`, `webdav_path_template`,
  `webdav_filename_template`, `webdav_store_xml`, and `webdav_store_pdf`.
  The response is the same safe GET representation, not the submitted secret.
- The browser uses the corresponding session routes
  `GET /{company_id}/nfse/settings/artifacts` and
  `PATCH /{company_id}/nfse/settings/artifacts`, preserving CSRF.

The WebDAV endpoint must be HTTPS without embedded URL credentials. Omitting
or leaving username/password fields blank retains the stored values **only
when the endpoint stays unchanged**; changing the endpoint does not reuse
credentials from the previous host. Supply new credentials when required.
An explicitly empty `webdav_url` disables storage and clears the credentials.
Connection checks occur before persisting, and failure produces HTTP 422
`reason=webdav_connection_failed`, without leaking upstream messages or
writing a partially validated configuration. No past XML, PDFs or fiscal
receipts are removed by this configuration change. The legacy fiscal settings
and certificate routes intentionally disabled with HTTP 410 remain disabled.


## Native fiscal cancellation, reissuance, substitution and synchronization

The HTTP-native Akaunting API exposes the existing fiscal transition handlers,
not a parallel implementation:

- `DELETE /{company_id}/api/nfse/invoices/{invoice}` — cancel current
  **emitted** fiscal receipt. Requires `nfse_receipt_id`, `cancel_reason`
  (`1`, `2` or `9`) and `cancel_justification` (15–255 characters).
- `POST /{company_id}/api/nfse/invoices/{invoice}/reemit` — reissue only
  after the **current** fiscal receipt was cancelled, with
  `nfse_receipt_id` and the same supported issuance options.
- `POST /{company_id}/api/nfse/invoices/{invoice}/substitute` — substitute
  the **current emitted** fiscal receipt; requires
  `nfse_substitution_receipt_id`, `nfse_substitution_reason` and
  `nfse_substitution_description`, as defined by the existing fiscal policy.
- `POST /{company_id}/api/nfse/invoices/{invoice}/refresh` — reconcile the
  persisted **current emitted/processing** receipt by `nfse_receipt_id`.
  No new DPS is submitted by the refresh operation.

All require native authentication, `update-sales-invoices` and exact tenant
ownership. Native clients must submit the current fiscal receipt ID; an
outdated ID, missing ID or incompatible state results in HTTP 409
`status=not_confirmed` without a new cancellation, substitution or emission.
The response includes links to independently authenticated fiscal status and
attempts; do not assume a transport failure proves SEFIN rejection.
Browser form routes preserve backward compatibility and invoke the same
application methods; the native transport never receives a browser redirect
or cookies. No existing authorized XML or receipt history is deleted.

An authorized reissuance is linked to the exact cancelled receipt ID submitted
by the caller and to the persisted emission-attempt journal. A repeated request
must inspect that history rather than allocate a new DPS. A success response
may include an NFS-e access key before the official NFS-e number becomes
available; in that case, the Web confirmation displays the **persisted access
key**, and API clients must use the status/receipt resources instead of
inferring a missing authorization from the absent number.
