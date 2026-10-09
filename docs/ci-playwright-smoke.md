<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Deterministic Playwright Smoke setup

`.github/workflows/playwright-smoke.yml` orchestrates actions and commands. The
setup logic lives in `scripts/ci/playwright-smoke.mjs` and is covered by
`frontend-tests/playwright-smoke-setup.test.mjs` using Node's built-in test
runner (no extra dependencies).

## Run the inexpensive checks

```bash
node --test frontend-tests/playwright-smoke-setup.test.mjs
npm run test:frontend
```

Both `Frontend Unit` and the `Playwright Smoke` job execute the setup tests.
The latter then runs the real Akaunting/SQLite browser suite, which catches
integration errors that isolated tests cannot.

## Command contract

Run the commands from the module root, in this order, in an isolated test
installation after checking out Akaunting and the module:

| Command | Purpose |
|---|---|
| `prepare-env` | Copy `.env.testing` to `.env`, override exact DB/mail/app settings, and create the SQLite file |
| `provision` | Create the deterministic admin and fiscal fixtures, validate their IDs, and write only numeric IDs to `GITHUB_ENV` |
| `assets` | Compile missing core browser assets using Node 20, then verify all expected outputs |
| `verify-db` | Assert the persistent SQLite test user exists |
| `expose-assets` | Link the module's static assets without replacing unrelated paths |
| `start-server` | Start Akaunting with test-only PFX settings and wait for the login route with bounded retries |

`provision` must run **after** migrations/seeders; `assets` requires the core's
Node/npm tooling; `start-server` runs after the database and asset checks.
The fixture export rejects absent, zero, negative, noninteger or injected IDs.
The harness JSON includes a synthetic password and must **not** be written to
logs or copied wholesale to GitHub environment files.

## Updating the setup

Update the exported fixture argument and field maps together when adding a
new scenario. Update the associated unit test before changing the workflow.
Keep behavior (parsing, fallbacks, filesystem operations, process startup) in
the script; keep only ordering and GitHub Actions integration in YAML. The
workflow contract test checks that required commands remain wired and that
inline shell parsing does not return.

This is an SQLite-only **test harness**, not a deployment script. Provisioning
refuses an environment other than `APP_ENV=testing` with `DB_CONNECTION=sqlite`.
Production fiscal rules and persistence listeners are not modified.
