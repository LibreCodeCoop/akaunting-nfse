<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Playwright test tiers

The browser suite has three explicit tiers.

## smoke

Runs on every pull request and on main. It provisions a local Akaunting instance,
a synthetic PKCS#12 certificate and deterministic fiscal transport. It never
contacts SEFIN or ADN and uses no fiscal secrets.

The smoke, full-ui and bulk-ui CI jobs each own an isolated GitHub-hosted runner
and SQLite file. Keep Playwright at one worker **per database**: sharing SQLite
and mutable fiscal fixtures across workers is unsafe. Parallelism between
isolated jobs is safe. No database, PFX files or tenant configuration are cached.

Smoke exercises the GitHub-hosted runner's preinstalled stable Chrome channel;
it does not download Playwright's Chromium or install system packages. Full UI
and Bulk UI use cached, version-matched Playwright Chromium binaries and run
`playwright install chromium` (without an unconditional apt upgrade). If a runner
image no longer has the required browser libraries, browser execution must fail
visibly so its dependency requirements can be reviewed instead of silently
adding lengthy, unpinned system-package installation to every CI run.

## full-ui

Runs on relevant pull requests, main, nightly and on demand against an
isolated deterministic local Akaunting environment. Two CI shards each provision an
independent runner, SQLite database, app process and synthetic fiscal fixtures.
Each shard runs the native category form check first, then runs its Playwright
partition of the remaining Full UI cases (one worker per shard). The `full_ui`
aggregator preserves the required check and fails if either shard fails. Artifacts
have shard-specific names so debugging does not overwrite evidence. Composer download caches and content-addressed scoped-runtime caches avoid
rebuilding identical PHP dependencies on warm runs. The scoped artifact key
includes the resolved, generated 3rdparty lockfile (not committed), the
PHP-Scoper config and tool lockfile; on a miss the runtime is rebuilt. No
SQLite, PFX, credentials or mutable test fixtures are cached or shared between
shards. This tier covers broader
settings, modal and invoice UI behavior while the fiscal boundary remains fake.

The four isolated Akaunting Feature shards reuse the same immutable scoped
PHP runtime cache. They check for the required Poppler executables before
falling back to installing OS packages, avoiding an unconditional apt index
update on runners where these utilities are already installed.

## live-fiscal

Runs only manually against a dedicated protected Akaunting environment. The
environment holds the real A1/OpenBao material; the runner must not download or
print the certificate or its password.

The default live test is read-only and performs an ADN distribution query. Real
emission and re-emission are separate opt-ins and run with zero Playwright
retries.

The synthetic certificate used by deterministic tests proves PKCS#12/signing
plumbing only. It does not prove ICP-Brasil trust or real SEFIN mTLS acceptance.
