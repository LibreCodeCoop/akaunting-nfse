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

## ADN distribution, review and import

ADN has one underlying synchronization/import implementation. External clients use
Akaunting's native API authentication and `read-api`; the administrative Web UI
uses its own authenticated session and CSRF protection. The two transports call
the same controller and domain services. They do not synchronize via internal
HTTP calls.

| Operation | Native API suffix | Browser-session suffix | Permission |
| --- | --- | --- | --- |
| Locally synchronized documents | `GET adn/documents` | `GET adn/documents` | `read-purchases-bills` |
| Accounting preview | `GET adn/documents/{id}/preview` | `GET adn/documents/{id}/preview` | `read-purchases-bills` |
| Live contributor distribution | `GET adn/distribution` | `GET adn/distribution` | `read-nfse-settings` |
| Events for an issued invoice | `GET invoices/{id}/adn-events` | `GET invoices/{id}/adn-events` | `read-sales-invoices` |
| Ignore received document | `POST adn/documents/{id}/ignore` | `POST adn/review/{id}/ignore` | `update-purchases-bills` |
| Import as draft bill | `POST adn/documents/{id}/import` | `POST adn/review/{id}/import` | `create-purchases-bills` |

Prefix native suffixes with `/{company_id}/{api_prefix}/nfse/` and browser
suffixes with `/{company_id}/nfse/`. The browser imports and ignores by
submitting the native JSON representation over its session-protected transport.
The original HTML forms remain functional without JavaScript.

The persisted document list supports `limit=1..100` (default 50),
`page`, `review_status`, and `fiscal_role`. Results contain `data`
and `meta` pagination information; raw XML is deliberately absent.
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
modified by a review action.

The live distribution query `GET adn/distribution?nsu=0&lote=1` calls the
existing company-certificate-backed ADN client. It does not persist
documents or advance synchronization state. Automatic sync still uses
`AdnSynchronizationService` via the existing jobs/commands. The existing
company-specific reconciliation is preserved; no alternate sync or
financial document writer is introduced.
