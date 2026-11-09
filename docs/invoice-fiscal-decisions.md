<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Invoice fiscal decisions

Fiscal decisions are separate from the Akaunting invoice, payments, fiscal receipts and fiscal group states.

- **Do not issue** (do_not_issue) is an operator instruction or a prospective category default, not legal evidence of NFS-e exemption.
- **Not applicable** (not_applicable) is an operator's documented assessment requiring a justification and evidence reference. Neither invoice category nor absence of local receipt establishes non-applicability.
- **Issue** (issue) is an audited invoice-specific exception allowing issuance when its category normally suppresses emission. It does not transmit a DPS on its own.
- **Reversed** (reversed) reverses the latest explicit invoice decision and restores the category default in force when the invoice was created; it does not delete previous decisions.
- Missing local receipts require external reconciliation before concluding that no invoice was issued. Pending or processing operations may require recovery. Cancellation and substitution history remains independent of this classification.
- Pending or ambiguous durable DPS attempts also block non-issuance until reconciled, even when no fiscal receipt has been imported yet. Unrecognized local receipt states fail closed.
- An authorized local receipt blocks both non-issuance choices. The operator must explicitly reconcile the valid fiscal document; this module never deletes the receipt or silently overrides it.
- For multi-group invoices, the existence of any authorized receipt blocks new invoice-wide non-issuance decisions; existing group-level partial/authorized states remain visible. A later authorized receipt takes precedence over an earlier decision in search results.
- Documents, payments, financial status, authorized XML, DPS identity and fiscal receipts are not modified by decisions.
- With the emit-on-send policy, an active decision skips NFS-e issuance but does not block the financial invoice's native customer-delivery workflow. Other fiscal preflight failures still preserve the existing fail-closed send behavior.
- Only the active company can query or write decisions for its invoices. Read requires read-sales-invoices; recording/reversing requires update-sales-invoices. An authenticated actor is mandatory.
- Decisions are append-only and record company_id, invoice_id, decision, reason, justification, evidence_reference, actor_id, and recorded_at.
- The same JSON contract is available through authenticated Web and native API: GET/POST nfse/invoices/{invoice}/fiscal-decision. Web forms redirect; JSON clients receive a created event or explicit validation error.
- Native nfse_status search supports do_not_issue and not_applicable before pagination. The commercial review section reports these decisions independently; authorized fiscal XML totals and the fiscal closing CSV are unchanged.

## Authorize NFS-e on the native category form

Create or edit an income category under **Akaunting > Settings > Categories**.
Within the same category form, the NFS-e module adds the **NFS-e issuance**
section. An operator with `update-nfse-settings` may explicitly authorize
`issue` or prohibit `do_not_issue` for that category and provide a
justification (12-4000 characters) when changing the authorization. Saving the
native category form also saves the fiscal policy **within the same database
transaction**. A failure in the fiscal policy write rolls back the category
save. If authorization has not changed, no new audit event is written.

An unconfigured category is **not authorized**, and no NFS-e should be inferred
from its financial records. Existing company-scoped policies and their history
are preserved. Read-only access displays the current authorization without
allowing it to be changed. To edit fiscal settings, both the native category
authorization and `update-nfse-settings` are required; users lacking fiscal
write permission can still edit unrelated native category fields.

An enabled income category must have a current company-scoped `issue` rule
to permit **new** NFS-e issuance. A non-income, disabled, unconfigured or
`do_not_issue` category stays outside that scope. Parent categories do not
implicitly authorize children. None of these settings establishes a tax
exemption. Existing linked NFS-e receipts stay visible and unchanged.

The NFS-e settings page points to the native category list; the former
`GET /nfse/settings/category-issuance` redirects HTML browsers there for
bookmarks, while JSON requests retain their previous company-scoped response.
The authenticated web POST and API GET/POST policy contracts remain available
to integration clients. Fiscal reads require
`read-nfse-settings`; changes require `update-nfse-settings`.

An individual invoice decision does not grant a category exemption or bypass
its issuance allowlist. Reversals, partial issuance, cancellation and
substitution retain separate audit and recovery rules. No history or financial
documents are rewritten while changing a category policy.

Eligibility applies to existing and future invoices on **read** and immediately
before the first irreversible DPS POST (manual, queue, API, grouped and bulk).
It never changes their Akaunting financial status, payments, receipts, or
historical recorded decisions. For an invoice outside the allowlist and with
no fiscal receipt, the invoice shows only the normal commercial interface:
no fiscal panel, no pending badge, no fiscal emission choice, and no entry in
NFS-e pending listings/filters. A receipt that already exists remains visible
for reconciliation, artifact access and cancellation even if the category has
since been disallowed; a new substitution or reissue still needs a currently
authorized category. Recovery of an already submitted DPS is not a first POST.

The latest category policy determines current issuance eligibility; invoice
decisions preserve their original fiscal snapshots. Re-enabling a category
requires an explicit authorization. A category name never proves legal
`not_applicable` status.

Operational classification of legacy invoices should use aggregate evidence:
verify each excluded category is non-service, inspect existing receipts and
unresolved transmission attempts, then apply an explicit category policy.
Do **not** write mass `not_applicable` decisions, delete receipts or transmit
documents while reconciling historical invoices. The current eligibility can
be audited through the company-scoped category-policy history and the
fiscal-review status `outside_issuance_scope`.
