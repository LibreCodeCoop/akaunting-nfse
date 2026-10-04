<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Native Akaunting invoice integration

The native Akaunting invoice page is the primary operational surface for NFS-e.

The module injects its fiscal panel through Akaunting's existing
`status_message_end` Blade stack. It does not replace or copy the core
`sales.invoices.show` view.

The panel currently exposes:

- pending/issued/cancelled/substituted fiscal state;
- latest NFS-e number and access key;
- direct issue/re-issue action through the existing native send modal flow;
- a link to detailed fiscal artifacts/audit information;
- a link to NFS-e settings.

During migration, `/nfse/invoices/{invoice}` remains the detailed fiscal/audit
view. Routine fiscal operations should progressively move to the native invoice
surface before the parallel list/detail is removed or redirected.
