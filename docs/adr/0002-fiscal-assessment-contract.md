<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0002: Immutable fiscal facts and source-backed decisions

Status: Proposed for review

## Boundary

The Akaunting adapter supplies immutable, company-scoped facts for one fiscal
group through `Contracts\FiscalContext`. Tax observations use
`Contracts\FiscalTaxFact` (document-item ID, tax ID, association-row ID,
accounting type, amount in integer cents, and explicitly classified fiscal
identity/role when verified). An absent ID or classification is `null`, never
guessed from tax names, percentages, a value or an XML element's historic name.

The fiscal application layer returns `Contracts\FiscalAssessment`, a list
of `Contracts\FiscalDecision` records. It must not mutate an Akaunting
invoice, authorized XML, accounting taxes or historic documents. The DTOs
encode no legislation, formula, municipal rate or emission strategy.

## Time and evidence

The context carries **both** the taxable-event date and service-competence
date. A decision names its stable rule ID, category, outcome, severity, primary
source URL/provision/version, source publication date, applicability interval,
optional affected field, corrective-action code and observed/expected values
in integer cents. Jurisdiction and taxpayer facts must be supplied explicitly;
an unknown fact is not a default tax rate or exemption. The provider's
cooperative legal form is independent of its tax regime. Gross invoiced
amount is distinct from optional actually paid amount and payment date:
missing payment evidence must not be silently treated as payment.

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
