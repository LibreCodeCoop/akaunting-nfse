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

## Category defaults and individual exceptions

The module settings include a dedicated **Issuance by category** screen. An administrator with `update-nfse-settings` can set each company-owned income category to **Issue NFS-e** or **Do not issue automatically**, with a mandatory justification. Category settings are append-only with an actor, timestamp and prior versions. A missing setting preserves the prior issuance workflow. They never imply a legal non-applicability determination.

A category policy becomes effective **only for commercial invoices created after the policy was saved**, whose issued date is not earlier than the policy date. Existing and historically imported invoices are not retroactively reclassified; manual invoice decisions remain possible. The current invoice category is used for lookup, so changing a category on an existing invoice warrants individual fiscal review.

An explicit invoice decision takes precedence over the category setting: `issue` permits an issuance exception, `do_not_issue` suppresses it, `not_applicable` requires documentary evidence, and `reversed` restores the category default. These are separate from authorization, cancellation, substitution and partial issuance states. The UI presents mutually exclusive **Issue NFS-e** and **Record a non-issuance decision** paths, with technical details and history collapsed.

The module exposes company-scoped category configuration through authenticated web and API GET/POST endpoints under `/nfse/settings/category-issuance`. Read requires `read-nfse-settings`; updates require `update-nfse-settings`. Any issuance guard checks the effective decision, including the final pre-transmission DPS check. A category rule cannot delete or hide a valid linked fiscal receipt.

An operator decision is not a fiscal/legal determination, does not establish a tax deadline, and does not authorize external issuance. No historical invoices are automatically recategorized or classified.
