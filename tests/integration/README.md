<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Integration test contracts

Behat lives under `vendor-bin/behat`, independent of the application runtime.

## Tags

- `@api`: the behavior under test is a JSON HTTP API contract, not merely an HTTP request used for setup.
- `@web`: browser-facing routes, forms, redirects, or rendered HTML, even when executed without a browser.
- `@external`: requires a separate local fake service (SEFIN, ADN, etc.); never means that tests may contact an official fiscal endpoint.

Tag the interface under test, not the internal transport. Do not create manual worker/shard tags; measure serial timing and verify isolation first. A scenario may have multiple tags where applicable.

The historical scenarios in `nfse_endpoints.feature` are `@web` because they exercise administrator pages and CSRF form submissions. They are not proof of API contract coverage.

## Scenario rules

Each scenario must arrange its own data and user permissions using only synthetic company/users/certificates and the existing test harness. Assert statuses, content types, JSON field types and observable state changes. In particular, never substitute a 200/502 allowance for an assertion of a specific deterministic contract. Do not depend on a preceding scenario.

The current authenticated legacy scenario setup mutates settings; use only a disposable database. `@external` scenarios may run only against a controlled synthetic service. Avoid using shared development instances and never print credential values in reports.

## Local commands

Install the tool using `composer --working-dir=vendor-bin/behat install`. Run with `composer test:behat` using `behat.php`; select the web tests with `--tags=@web`, JSON contracts with `composer test:behat:api`, and exclude local external services with `--tags="not @external"`. The target server is configured with `NFSE_BEHAT_BASE_URL`. Authenticated legacy scenarios also require synthetic `NFSE_BEHAT_EMAIL` and `NFSE_BEHAT_PASSWORD`.

Only run these tests against disposable Akaunting `APP_ENV=testing` environments.
