<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Akaunting core integration audit

This document records the upgrade-sensitive Akaunting integration seams tracked by
issue #190. The reference core is the Akaunting `main` layout reviewed on
2026-10-04.

## Invoice send lifecycle

The native Akaunting Send action is no longer repurposed as a fiscal button.

Fiscal actions live in the NFS-e panel injected into the native invoice page. In
manual policy mode, the operator emits/re-emits/cancels from that panel while
Akaunting Send keeps its ordinary customer-delivery semantics.

With `emit_on_send`, the module listens to Akaunting's native
`DocumentSending` event. Fiscal preflight and issuance therefore happen before
customer delivery, and a failure aborts the normal send job. Repeated sends reuse
an already-issued receipt.

The NFS-e custom email path also dispatches `DocumentSending`/`DocumentSent`
so it cannot bypass the authoritative lifecycle.

## Item create/edit views

Remaining overrides:

- `Resources/overrides/common/items/create.blade.php`
- `Resources/overrides/common/items/edit.blade.php`

The only intentional behavioral addition is the NFS-e fiscal-fields partial.
Current Akaunting item create/edit templates do not expose a Blade stack,
component slot, event, or module hook at the form-section boundary where a
module can insert this section.

Because no narrower supported seam exists, the overrides remain compatibility
adapters. Fiscal profile persistence itself does **not** depend on these views.
Akaunting's native `ItemCreating` and `ItemUpdating` events run *before*
the transaction in `CreateItem`/`UpdateItem`; the post-save `ItemCreated`
and `ItemUpdated` events run *after* it. The NFS-e request-scoped listener
receives the native pre-save event and persists the fiscal profile from Eloquent
`Item::saved` **inside** the native `DB::transaction`. The `Item::saving`
hook checks that the native transaction is active and uses the fiscal table's
connection before commercial persistence. Fiscal exceptions propagate through
the native transaction, rolling back commercial and fiscal writes and causing
the native `ajaxDispatch` to return `success: false`, including API/AJAX.

Only explicit fiscal fields in a `ValidatedFiscalItem` request arm the
persistence listener. Imports, non-HTTP operations, other-company items and
commercial-only edits must not trigger fiscal changes. `ItemFiscalProfileInput`
continues to preserve unchanged historical codes, omitted fiscal fields, RTC
category and explicit removal. A transaction protects database writes only:
native image/filesystem side effects cannot be rolled back, and queue settings
must be assessed when upgrading Akaunting. In particular, jobs dispatched
asynchronously may need a separately supported integration boundary; do not
claim their external effects are atomic.

When Akaunting exposes a form-extension seam, these two overrides should be
removed rather than kept in sync indefinitely.

## Document send components

The module no longer overrides Akaunting's
`components/documents/show/send.blade.php` or `more-buttons.blade.php`.
Those compatibility overrides became unnecessary once manual fiscal actions
moved into the native fiscal panel and automatic issuance moved into
`DocumentSending`.

This restores core Send/Mark Sent behavior and removes an upgrade-sensitive
template seam.

## Global Blade path

The global override path remains only for the item create/edit compatibility
files listed above. It must not be used for new small UI additions. New
integrations should prefer Akaunting events, jobs, stacks, components, or scoped
view composers.

## Upgrade checklist

For each supported Akaunting upgrade:

1. compare the two item create/edit overrides with their new core counterparts;
2. check whether a native form/action extension seam now exists;
3. remove an override as soon as a narrower seam can preserve behavior;
4. run Akaunting Feature and deterministic Playwright tiers before accepting the
   upgrade.
