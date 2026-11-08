<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Invoice fiscal decisions

Fiscal decisions are separate from the Akaunting invoice, payments, fiscal receipts and fiscal group states.

- **Do not issue** (do_not_issue) is an operator instruction, not legal evidence of NFS-e exemption.
- **Not applicable** (not_applicable) is an operator's documented assessment requiring a justification and evidence reference. Neither invoice category nor absence of local receipt establishes non-applicability.
- **Reversed** (reversed) restores eligibility for manual/automatic issuance, without deleting the prior decision.
- Missing local receipts require external reconciliation before concluding that no invoice was issued. Pending or processing operations may require recovery. Cancellation and substitution history remains independent of this classification.
- Pending or ambiguous durable DPS attempts also block non-issuance until reconciled, even when no fiscal receipt has been imported yet. Unrecognized local receipt states fail closed.
- An authorized local receipt blocks both non-issuance choices. The operator must explicitly reconcile the valid fiscal document; this module never deletes the receipt or silently overrides it.
- For multi-group invoices, the existence of any authorized receipt blocks new invoice-wide non-issuance decisions; existing group-level partial/authorized states remain visible. A later authorized receipt takes precedence over an earlier decision in search results.
- Documents, payments, financial status, authorized XML, DPS identity and fiscal receipts are not modified by decisions.
- Only the active company can query or write decisions for its invoices. Read requires read-sales-invoices; recording/reversing requires update-sales-invoices. An authenticated actor is mandatory.
- Decisions are append-only and record company_id, invoice_id, decision, reason, justification, evidence_reference, actor_id, and recorded_at.
- The same JSON contract is available through authenticated Web and native API: GET/POST nfse/invoices/{invoice}/fiscal-decision. Web forms redirect; JSON clients receive a created event or explicit validation error.
- Native nfse_status search supports do_not_issue and not_applicable before pagination. The commercial review section reports these decisions independently; authorized fiscal XML totals and the fiscal closing CSV are unchanged.

An operator decision is not a fiscal/legal determination, does not establish a tax deadline, and does not authorize external issuance. No historical invoices are automatically recategorized or classified.
