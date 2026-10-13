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
isolated jobs is safe. Only versioned Chromium binaries are cached; never
cache the database, PFX files or tenant configuration.

## full-ui

Runs on relevant pull requests, main, nightly and on demand against an
isolated deterministic local Akaunting environment. It covers broader settings, modal and invoice UI behavior while the
fiscal boundary remains fake.

## live-fiscal

Runs only manually against a dedicated protected Akaunting environment. The
environment holds the real A1/OpenBao material; the runner must not download or
print the certificate or its password.

The default live test is read-only and performs an ADN distribution query. Real
emission and re-emission are separate opt-ins and run with zero Playwright
retries.

The synthetic certificate used by deterministic tests proves PKCS#12/signing
plumbing only. It does not prove ICP-Brasil trust or real SEFIN mTLS acceptance.
