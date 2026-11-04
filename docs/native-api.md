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
