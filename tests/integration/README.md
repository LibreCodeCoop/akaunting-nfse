<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# NFS-e HTTP integration tests

The isolated Behat 4 installation is `vendor-bin/behat`. It runs requests
against **actual Akaunting HTTP routes**, not controllers invoked in-process.
Use `behat.php`, not the obsolete Behat 3 YAML configuration.

## Tags and context ownership

- `@api` means the **asserted behavior** is a JSON HTTP contract of the module, not merely setup over HTTP.
- `@web` means server-rendered pages, redirects or forms. HTTP does not automatically imply `@api`.
- `@external` means a separately running **local synthetic service** is required (for example fake SEFIN or ADN). It must not call any public government endpoint. These scenarios are excluded from default CI until their service lifecycle is explicitly provisioned.

Do not introduce domain, shard or worker tags as scheduling hints. Measure the serial
runtime before introducing concurrency. Generic HTTP/Akaunting login and JSON
assertions belong to `AkauntingHttpContext`; NFS-e-specific tenant/receipt checks
belong to `NfseApiContext`. The historical HTML checks use `FeatureContext`.

All steps use Behat 4 attributes, `Behat\Step\DataTable` and
`Behat\Step\DocString` where appropriate. Assertions check business-visible
status, typed JSON properties, authorization and isolation rather than HTML
implementation details.

## Disposable test environment

Use the established Akaunting CI harness lifecycle:

1. Checkout Akaunting core into `akaunting` and NFS-e under `akaunting/modules/Nfse`.
2. Set `APP_ENV=testing`, `DB_CONNECTION=sqlite` and a **new private**
   SQLite database path. Never point the harness at the shared development
   Docker database.
3. Run `node scripts/ci/playwright-smoke.mjs prepare-env` from the module,
   install the core and scoped module runtime, then `php artisan migrate:fresh
   --force` and the native `Database\Seeds\TestCompany` seeder **inside the
   disposable core only**.
4. Run the existing `playwright-smoke.mjs provision` profile for synthetic
   test user, pending invoice and synthetic NFS-e cert. With
   `NFSE_TEST_HARNESS=1`, `php artisan tinker --execute='require
   "modules/Nfse/scripts/ci/behat-fixtures.php";'` provisions a second native
   Akaunting company with its own synthetic profile and account plus a role
   without fiscal permissions.
5. Start the disposable HTTP server with the existing
   `playwright-smoke.mjs start-server`, run Behat, and stop it through
   `playwright-smoke.mjs stop-server` even on failure.

The GitHub Actions workflow performs all preparation on a fresh hosted runner.
It does not require the local Docker stack, real accounts, official fiscal
credentials or a real SEFIN/ADN service. It never invokes `migrate:fresh`
against a persistent shared installation.

The BEHAT accounts are intentionally fake and defined only within the
disposable CI workflow. When running locally, supply
`NFSE_BEHAT_BASE_URL`, `NFSE_BEHAT_COMPANY_ID`,
`NFSE_BEHAT_OTHER_COMPANY_ID`, `NFSE_E2E_PENDING_INVOICE_ID`, and the
three synthetic account email/password pairs described in the CI workflow.
Do **not** point any of these steps to production data.

## Commands

```sh
composer --working-dir=vendor-bin/behat install
composer test:behat                     # full local suite, requiring synthetic fixtures
composer test:behat:api                 # JSON contracts, no separately hosted fakes
composer test:behat:ci                  # all serial non-external scenarios
composer test:behat:guest               # unauthenticated browser-facing routes
vendor-bin/behat/vendor/bin/behat --config=behat.php --tag-expression='@api and not @external'
vendor-bin/behat/vendor/bin/behat --config=behat.php tests/integration/features/nfse_api_contracts.feature
```

To emit JUnit alongside terminal progress, run:

```sh
mkdir -p build/behat-junit
vendor-bin/behat/vendor/bin/behat --config=behat.php \
  --tag-expression='not @external' --format=progress --out=std --format=junit --out=build/behat-junit
```

GitHub Actions stores JUnit and sanitized server diagnostics as artifacts on
failure. Feature/scenario names are preserved in the report; assertions must
never expose raw response bodies, credentials, private certificate bytes or
personal data.

## Isolation and coverage contract

Each Behat scenario creates its own HTTP session and authenticates a specified
synthetic user. Database fixtures are provisioned *before* scenarios, contain
no customer data, and remain read-only during successful contract checks.
Negative mutation tests must be rejected **before** the application can mutate
tenant state or fiscal state. The pending-invoice status is checked after denied
issuance to detect authorization regressions. A second native company has a
synthetic profile with the **same** provider CNPJ, to detect accidental
cross-company access despite equal fiscal identities.

Catalog pagination is tested with an explicit result bound. The existing
journal/status endpoints are read without transmitting a DPS. Do not create
an endpoint merely for Behat/MCP, perform real fiscal emission, or turn a
transmission ambiguity into an automatic retry. Future state-transition and
idempotency scenarios must use the approved local fake fiscal transport and
must be independently executable. Evidence of extra networking, non-isolated
state or order-dependent results blocks concurrency.
