<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Test fixtures and CI performance budget


## Shared test data

Tests should reuse the narrowest existing fixture owner:

- **Akaunting invoice/contact/accounting state:** use Akaunting's native model
  factories in Feature tests. Do not mirror core factories in this module.
- **NFS-e receipt and item fiscal profile state:** use
  `Tests\\Support\\FiscalScenarioBuilder`.
- **Fiscal HTTP:** use `Tests\\Support\\FakeHttpTransport`.
- **Secrets:** use `Tests\\Support\\InMemorySecretStore`.
- **Certificates:** use `Tests\\Support\\SyntheticPkcs12`.

Builders keep fiscal values visible at each call site. They should remove
persistence boilerplate, not hide why a scenario is fiscally different.

## PR feedback budgets

The GitHub Actions job duration is the canonical measurement. Expensive
deterministic jobs have explicit hard budgets:

| Job | Budget |
| --- | ---: |
| PHPUnit matrix job | 15 min |
| Akaunting Feature | 20 min |
| Psalm | 15 min |
| Playwright Smoke | 30 min |
| Playwright Full UI | 30 min |

`CiPerformanceBudgetTest` protects these limits from disappearing silently.

A timeout is a regression signal, not permission to increase the number. When a
job approaches its budget, first inspect dependency installation, fixture
duplication, unnecessary environment rebuilds and test partitioning.

## Tier policy

Pull requests keep the fast deterministic gates:

- PHPUnit;
- Psalm;
- coding style / REUSE / commit policy;
- Akaunting Feature;
- Playwright Smoke.

Full UI remains on `main`, scheduled and manually runnable. Live fiscal flows
remain explicit/manual because they mutate an external fiscal environment.

Path filters may be introduced only when the skipped job cannot cover behavior
affected by the changed path. Main/scheduled coverage must remain complete for
expensive tiers omitted from PRs.

## Reviewing duration changes

Reviewers should compare the Actions job duration with recent successful runs.
A persistent material increase should be explained in the PR. Increasing a
`timeout-minutes` value requires an explicit rationale and should not be used
to mask a deterministic slowdown.
