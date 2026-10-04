<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Akaunting core integration audit

This document records the upgrade-sensitive Akaunting integration seams tracked by
issue #190. The reference core is the Akaunting `main` layout reviewed on
2026-10-04.

## Invoice send route

The module changes Akaunting's document configuration for the native invoice
show page so the existing Send action opens the NFS-e modal and uses the fiscal
label (emit, cancel, or re-emit).

The previous implementation performed the same mutation twice:

1. from a global `RouteMatched` listener; and
2. from a view composer scoped to `sales.invoices.show`.

The route listener is unnecessary. The view composer is narrower: it runs only
while rendering the native invoice page and receives the actual invoice model.
The `RouteMatched` listener has therefore been removed.

Feature characterization in `InvoiceLifecycleCharacterizationTest` protects the
native invoice page, the configured modal route, pending/emitted/cancelled
actions, and the no-items fallback.

## Item create/edit views

Remaining overrides:

- `Resources/overrides/common/items/create.blade.php`
- `Resources/overrides/common/items/edit.blade.php`

The only intentional behavioral addition is the NFS-e fiscal-fields partial.
Current Akaunting item create/edit templates do not expose a Blade stack,
component slot, event, or module hook at the form-section boundary where a
module can insert this section.

Because no narrower supported seam exists, the overrides remain compatibility
adapters. Fiscal profile persistence itself does **not** depend on these views:
it uses Akaunting's native `ItemCreated` and `ItemUpdated` events.

When Akaunting exposes a form-extension seam, these two overrides should be
removed rather than kept in sync indefinitely.

## Document send components

Remaining overrides:

- `Resources/overrides/components/documents/show/more-buttons.blade.php`
- `Resources/overrides/components/documents/show/send.blade.php`

Akaunting exposes stacks around the send controls, but those stacks can only add
content. They cannot replace the core email-enabled condition. The NFS-e action
must remain available when a fiscal invoice can be emitted even if the contact
has no email address; customer email is a separate post-emission concern.

The overrides therefore change only that eligibility decision while preserving
the core actions/stacks. Removing them without a core replacement seam would
regress the native fiscal flow characterized by Feature and Playwright tests.

## Global Blade path

The global override path remains only to resolve the four compatibility files
listed above. It must not be used for new small UI additions. New integrations
should prefer Akaunting events, jobs, stacks, components, or scoped view
composers.

## Upgrade checklist

For each supported Akaunting upgrade:

1. compare the four overridden templates with their new core counterparts;
2. check whether a native form/action extension seam now exists;
3. remove an override as soon as a narrower seam can preserve behavior;
4. run Akaunting Feature and deterministic Playwright tiers before accepting the
   upgrade.
