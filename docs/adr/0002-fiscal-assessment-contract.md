<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0002: Immutable fiscal facts and source-backed decisions

Status: Proposed for review

## Boundary

The Akaunting adapter supplies immutable, company-scoped facts for one fiscal
group through `Contracts\FiscalContext`. Tax observations use
`Contracts\FiscalTaxFact` (optional document-item ID, tax ID, association-row ID,
accounting type, amount in integer cents, and explicitly classified fiscal
identity/role when verified), the observed tax-specific base and exact decimal
rate when available, and explicit provenance. A tax-row amount may be null
when not actually observed; integer zero is a distinct observed fact.
Roles are backed by
`FiscalTaxRole` (provider due, withheld by taker, transparency estimate).
An invoice-level tax need not invent a document-item ID of 0.
An absent ID, value origin or classification is `null`, never
guessed from tax names, percentages, a value or an XML element's historic name.

The fiscal application layer returns `Contracts\FiscalAssessment`, a list
of `Contracts\FiscalDecision` records. It must not mutate an Akaunting
invoice, authorized XML, accounting taxes or historic documents. The DTOs
encode no legislation, formula, municipal rate or emission strategy.

## Time and evidence

The context carries **both** the taxable-event date and service-competence
date. Neither date, tax regime, transaction category, taker type nor gross
amount is fabricated when unavailable: `null` means the fact cannot yet
be verified. The tax-fact list likewise distinguishes `null` (not
loaded/unknown) from an observed empty list, and a monetary zero from an
unavailable monetary amount. A decision names its stable rule ID, category, outcome, severity, primary
source URL/provision/version, source publication date, applicability interval,
optional affected field, corrective-action code and observed/expected values
in integer cents. Jurisdiction and taxpayer facts must be supplied explicitly;
an unknown fact is not a default tax rate or exemption. The provider's
cooperative legal form is independent of its tax regime. Gross invoiced
amount is distinct from optional actually paid amount and payment date:
missing payment evidence must not be silently treated as payment.

The context separately retains the observed DPS `opSimpNac`,
`regApTribSN` and `regEspTrib` codes, the applicable IBS/CBS collection
regime (Simples or regular), the governmental sphere of the taker
(federal, state, district or municipal, when verified), and the verified
CNPJ legal-nature code. The context also records whether the
actual payer is proven to be the invoiced taker and whether the operation
is a sale to a final consumer. Unknown payer identity and unknown
consumer destination remain null, not a guess from the invoice name or
CPF/CNPJ. A public/private boolean cannot establish whether
the payer is subject to a particular IRRF/PIS/COFINS/CSLL withholding rule:
direct administration, autarchies, foundations and public enterprises have
different legal conditions, and state/municipal contributions may depend on
specific legal arrangements. Consumer-facing estimated-tax transparency
under Law 12.741/2012 and Decree 8.264/2014 also requires knowing
whether the operation is a consumer sale; the MEI dispensation and
Simples disclosure options are independently assessed. These are
**facts** only, not proof of an
allowed tax treatment. A null special-regime code means "unknown"; code 0 means
explicit "none". Applicability, valid enumerations for a particular
published schema, municipality-specific restrictions, and elections by
effective period belong to versioned policies and protocol validation.
Applicability dates are inclusive at both ends; a policy must select the
appropriate calendar date from the operation facts instead of treating
publication or transmission time as its taxable-event date.

A rule's legal applicability is evaluated on the relevant event/competence
facts. Protocol compatibility is evaluated **separately** against the
environment-specific active schema and deployment. A newly published NT/XSD
does not automatically become the active production emitter. A successful
gateway response does not retroactively make an incomplete obligation legal.

## Outcomes and ownership

The permitted decision categories are `normative`, `calculation`,
`document` and `protocol`; statuses are `pass`, `fail`,
`not_applicable`, `unverifiable` and `unsupported`; severity is
`block`, `warn` or `info`. A consumer must not equate
`unverifiable` with `pass`, nor assume every unavailable advisory
municipal query is a fiscal rejection. Where a demonstrably mandatory
requirement cannot be satisfied, the emission preflight blocks at the common
last boundary before the fiscal POST.

The module owns context adaptation, statutory obligation and monetary-rule
assessment. `nfse-php` owns active versioned XML serialization, signature,
transport, XML schema validation and faithful DANFSe rendering from authorized
data. Previously authorized artifacts stay immutable. Actual rule strategies,
test scenarios and rollout decisions live in their respective implementation
work; this ADR deliberately defines no generic rule engine, DSL or arbitrary
database-configured tax formulas.
