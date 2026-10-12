<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Akaunting tax identity: accounting observations versus fiscal incidence

Status: confirmed fiscal facts connected to the federal payload; **not legal emission approval**.

## Source of truth and company boundary

A native Akaunting `taxes` record identifies an accounting tax only by
`company_id` and `tax_id`. Names, aliases, CNPJ, and percentages never assign
the legal identity or fiscal role. A confirmed association in
`nfse_tax_fiscal_associations` records `fiscal_identity`,
`fiscal_role`, an effective date interval, operator confirmation and
a source reference. A new, disjoint interval is required to change the
classification. The append-only confirmation service locks the actual
company-owned native tax and rejects overlap; the reader independently
rejects concurrent valid revisions. There are no automatic legacy mappings
or silent name-based fallbacks.

The `InvoiceTaxFactsResolver` reads the persisted
`document_item_taxes` rows joined to the owning invoice, item and
accounting tax, constrained by `company_id`. It retains a historical
item-tax observation when the native tax has been soft-deleted, and reads
amounts and rates as decimal text rather than PHP floats. An unknown or
conflicting association must stay unclassified or fail with a diagnostic.
The tax-specific taxable base is **null** until lawfully established; it
does not default to the document total. Already authorized XML and PDF
snapshots remain immutable.

## Accounting types are not fiscal rules

| Akaunting type | Accounting behavior | Fiscal treatment in this groundwork |
| --- | --- | --- |
| `normal` | Exclusive percentage tax | Can record provider-due observation, not prove incidence/base |
| `withholding` | Reduces commercial amount | Requires verified withheld-by-payer role; no assumed payer |
| `inclusive` | Embedded in listed amount | Unsupported without specific base and extraction proof |
| `compound` | May depend on prior tax values | Unsupported without ordered statutory calculation proof |
| `fixed` | Fixed monetary tax | Unsupported without operation-specific legal authorization |

`FiscalTaxRole` distinguishes provider due, actually withheld by payer,
and estimated disclosure. Estimated transparency under Law 12.741/2012
uses independent versioned sources, not an Akaunting normal tax. IBS/CBS
obligations are assessed per operation and temporal law, not by installing
an ordinary tax row in the invoice. Tax identity alone cannot establish
legal applicability, tax base, rate, taxable event, rounding, who actually
paid, or the tax regime. The #427 source-backed matrix and #429 common
emission preflight govern these decisions separately.

## NT007 evidence and arithmetic

Official NT007 version 1.0 dated 2026-02-07 (section 2(c), pp. 4 and 6,
published for production and production-restricted availability on
2026-02-09):
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-007-se-cgnfse-v1-0.pdf

- `vPis` / `vCofins` are provider-due amounts, **not** amounts withheld.
- `vRetCSLL` represents the sum of actual PIS/COFINS/CSLL withholdings
  selected by `tpRetPisCofins`, regardless of its historical label.
- PIS/COFINS value reconciliation specifies *half-even* rounding and a
  tolerance of R$0.01. The independent
  `ExactTaxDecimal::percentOfCentsHalfEven()` oracle does not apply
  itself to invoice totals or justify any fiscal base.

The official RTC index separately publishes NT009 v1.01 (2026-10-01),
Annex VI v1.04.01 and Annex VII v1.03.00:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc
Publication does not prove the version active in any specific gateway
environment or create a generic invoice-line CBS/IBS charge.

## Consumer contract (#429 and #431)

The operational InvoiceFederalPayloadResolver now consumes dated, confirmed
InvoiceTaxFactsResolver observations, scoped by company, document and selected
items. FederalTaxPayloadFacts aggregates exact integer cents by persisted-row
identity, separating due PIS/COFINS, retained contributions and IRRF.
Configured percentages, names, or the invoice total do not establish a tax
base or an amount. NT007 vRetCSLL consolidates selected confirmed social
withholdings, independently from vPis/vCofins. Type conflicts block emission.

The internal federalTaxEvidence output carries row count, base availability
and legal_incidence=unverifiable. This is **not** an XML field or proof of
compliance. The existing document_item_taxes rows have baseCents=null:
provider-due PIS/COFINS cannot be transmitted without independent lawful
base evidence. The common legal-operation preflight belongs to #429; operator
permissions and mapping UI belong to #431. No IBS/CBS tax-row promotion,
automatic aliases, reissued historical XML or mutation of DPS identities.

The legacy name classifier is not reachable from the production federal
payload resolver. Controller HTTP unit characterization uses a synthetic,
neutral federal payload; verified tax arithmetic and rejection use focused
unit tests and actual Akaunting Feature tests. A fake SEFIN that accepts
XML is not legal approval.

Association writes require the caller to authenticate and authorize an
operator for the specified company. Creating the operator-facing UI and
permission boundary is reserved for #431. No direct public write route
is provided by this groundwork.
