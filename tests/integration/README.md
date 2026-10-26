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

The canonical runner is `scripts/ci/behat-ci.mjs`. It reuses Akaunting's
existing test harness and the Playwright smoke setup, but runs independently
of GitHub Actions, without `GITHUB_ENV` or service credentials. It does not
use the shared `akaunting-docker` installation.

Provisioning is destructive **only inside the explicitly authorized test
checkout**. Use a separate Akaunting Git checkout containing NFS-e at
`modules/Nfse`, with its own SQLite file. Never point these commands at a
persistent customer, development or production installation. The runner checks
that the directory layout is correct, requires `APP_ENV=testing`,
`DB_CONNECTION=sqlite`, `NFSE_TEST_HARNESS=1`, and refuses setup unless
`NFSE_BEHAT_DISPOSABLE=1` is explicitly set.

The GitHub Actions workflow checks out a known-good, pinned Akaunting core
revision and performs these commands from `akaunting/modules/Nfse`. A local
run needs the same isolated directory layout. For example, in an independent
throwaway checkout:

```sh
export APP_ENV=testing DB_CONNECTION=sqlite NFSE_TEST_HARNESS=1 NFSE_BEHAT_DISPOSABLE=1
node scripts/ci/behat-ci.mjs prepare
node scripts/ci/behat-ci.mjs run
```

`prepare` installs dependencies, migrates the **disposable** SQLite database,
provisions the original synthetic NFS-e harness plus native second-company
fixtures and writes numeric IDs to private `build/behat/fixture-ids.json`.
The actor emails/passwords live in `scripts/ci/behat-actors.json`, are all
synthetic and are passed to the Behat process only. They are not copied into
GitHub workflow YAML or build reports. `run` starts the disposable Akaunting
server, executes all non-external scenarios serially and in randomized order,
writes JUnit, and stops the server even if an assertion fails.

The runner's `prepare` and `run` operations can be exercised locally without
GitHub Actions; unit tests for environment protection, fixture parsing,
redaction and cleanup run with `node --test frontend-tests/behat-ci-runner.test.mjs`.

### Direct Behat commands

After `prepare`, the runner is preferred because it supplies synthetic users
and fixture IDs automatically. For an already-running, isolated test server
with equivalent `NFSE_BEHAT_*` environment variables, the underlying tool is:

```sh
composer test:behat:ci
composer test:behat:api
composer test:behat:guest
```

JUnit reports are retained under `build/behat-junit/`. Sanitized diagnostic
logs are retained under `build/behat/server.log` on failure. CI publishes
these files when a job fails; it does not upload raw server logs.

### Supported PHP versions

`composer.json` is the source for the project's compatible PHP constraint and
explicit list of *tested* PHP minor versions (`extra.ci.php_versions`). The
PHPUnit workflow reads the tested list using `scripts/ci/php-matrix.mjs`.
The Behat HTTP integration runner uses PHP 8.3 and is **not** a functional
matrix across all PHP minors. Updating Akaunting's pinned core revision must
be accompanied by a fresh integration CI run.

## Isolation and coverage contract

Each Behat scenario creates its own HTTP session and authenticates a specified
synthetic user. Database fixtures are provisioned *before* scenarios, contain
no customer data, and remain read-only except for the single synthetic
issuance scenario. Negative mutation tests must be rejected **before** any
application code can mutate
tenant state or fiscal state. The pending-invoice status is checked after denied
issuance to detect authorization regressions. A second native company has a
synthetic profile with the **same** provider CNPJ, to detect accidental
cross-company access despite equal fiscal identities.

Catalog pagination is tested with an explicit result bound. The existing
journal/status endpoints are queried through actual Akaunting routes.
The issuance case provisions a separate ready invoice using the existing
test-only harness and uses the in-process fake fiscal transport to simulate
SEFIN; duplicate HTTP actions must retain one authorized attempt and receipt.
Do not create an endpoint merely for Behat/MCP, call real fiscal services,
or retry on ambiguous fiscal outcomes. Each scenario must be independently
executable. Evidence of extra networking, leaked state or order dependence
blocks concurrency.
