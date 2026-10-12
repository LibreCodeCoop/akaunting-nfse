<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Deterministic Playwright browser setup

`.github/workflows/playwright-smoke.yml`, `playwright-full-ui.yml`, and
`playwright-bulk-ui.yml` orchestrate separate browser jobs. Their reusable
setup logic lives in `scripts/ci/playwright-smoke.mjs` and is covered by
`frontend-tests/playwright-smoke-setup.test.mjs` using Node's built-in test
runner (no extra dependencies).

## Run the inexpensive checks

```bash
node --test frontend-tests/playwright-smoke-setup.test.mjs
npm run test:frontend
```

The `Frontend Unit` and browser jobs execute the setup tests. Browser jobs
then run a real Akaunting/SQLite suite with a synthetic fiscal boundary, which
catches integration errors that isolated tests cannot.

## Command contract

Run the commands from the module root, in this order, in an isolated test
installation after checking out Akaunting and the module:

| Command | Purpose |
|---|---|
| `prepare-env` | Copy `.env.testing` to `.env`, override exact DB/mail/app settings, and create the SQLite file |
| `provision` | Create the deterministic admin and Smoke fiscal fixtures, validate their IDs, and write only numeric IDs to `GITHUB_ENV` |
| `provision-full-ui` | Create the Full UI fiscal fixture profile and export only its ten validated numeric IDs |
| `provision-bulk-ui` | Create the Bulk UI fiscal fixture profile and export only its two validated numeric IDs |
| `assets` | Compile missing core browser assets using Node 20, then verify all expected outputs |
| `verify-db` | Assert the persistent SQLite test user exists |
| `expose-assets` | Link the module's static assets without replacing unrelated paths |
| `start-server` | Start Akaunting with test-only PFX settings, record the PID and wait for the login route with bounded retries |
| `stop-server` | Terminate only the detached test-server group identified by its validated PID; succeeds if the PID file is absent |
| `run-smoke` | Run all 17 Smoke cases with Chromium exit diagnostics and periodic Chromium/PHP/Node RSS samples; preserve Playwright's exit code |

Use exactly one provisioning command for the selected tier, **after**
migrations/seeders. `assets` requires the core's Node/npm tooling; the compiled
Akaunting core assets are cached using core build inputs. `start-server` runs
after the database and asset checks, and `stop-server` runs after the start
step even if the browser test fails.
Smoke uses one worker and Playwright's normal fresh browser context/page per
test. The browser process may be shared by tests within that worker; this is
**not** process-per-test isolation. The Smoke project selects the installed stable Google Chrome via
`channel: 'chrome'` rather than either bundled Chrome for Testing binary.
The hosted runner must provide Chrome; Playwright fails closed if it is absent.
This probes whether the bundled-browser build is implicated in the observed
`SIGTRAP` crashes, without asserting a root cause or changing the full-ui tier.
The runner's browser launch path is included in `pw:browser` diagnostics. Browser retries are disabled, so a crash fails the run
instead of being reported as an apparently successful flaky test.
The runner records `pw:browser` stderr and aggregate process RSS (command
names only, no arguments or environment values); RSS is a diagnostic sample,
not proof of the Linux OOM killer. A browser exit, nonzero test exit or missing
resources is never reported as a successful test.

The fixture export rejects absent, zero, negative, noninteger or injected IDs.
The harness JSON includes synthetic passwords and must **not** be written to
logs or copied wholesale to GitHub environment files. Startup errors terminate
the detached process group, and CLI errors report the failed stage and duration;
subprocess execution has bounded timeouts.

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
