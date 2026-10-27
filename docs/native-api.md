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
- Query: optional `lc116` (four digits) and `q` (maximum 100 characters).
- Success: `200 application/json`, `{"data":[{"code":"...","description":"..."}]}`
  containing at most 50 entries. With no LC code and fewer than two search
  characters, `data` is empty.
- Invalid LC code or excessive query: `422 application/json` with empty `data`.
- Access denial: Core-controlled 401 or 403 JSON with `Accept: application/json`.
- The API prefix and Core API version remain configured by Akaunting, rather
  than a new module-owned authentication or version scheme.

Both routes call the **same** controller, using `nfse-php`'s
`OfficialDomainCatalog`. The existing fiscal item assistant uses the
browser-session route; it does not handle Basic passwords or Bearer tokens.
The two HTTP paths are deliberate security transports, not two domain APIs:
the API group is stateless, whereas the admin group includes session and CSRF.
Do not forward browser cookies into the native API group.

No write operation is exposed in this foundation. Future fiscal mutations
require distinct operation-level ACL, tenant and object checks, CSRF for cookie
authentication, idempotency and negative integration tests before publication.
The native Core API remains unchanged.
