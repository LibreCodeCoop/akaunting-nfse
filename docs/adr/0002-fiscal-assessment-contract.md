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
`FiscalTaxRole` (provider due, withheld by the actual payer, transparency estimate).
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
unavailable monetary amount.

A decision names its stable rule ID, category, outcome and severity.
For conclusive `pass`, `fail` and `not_applicable` results, it requires
the primary source URL, provision, version, publication date and effective
start. For `unverifiable` or `unsupported`, source and temporal evidence
may be null when genuinely unavailable; missing municipal consultation data
must not be replaced with fictitious legal provenance. Known evidence may
still be recorded, along with the affected field, correction code and
observed/expected integer-cent values. A source-free `unverifiable`
diagnostic is not legal authorization. Jurisdiction and taxpayer facts
must be supplied explicitly;
an unknown fact is not a default tax rate or exemption. The provider's
cooperative legal form is independent of its tax regime. Gross invoiced
amount is distinct from optional actually paid amount and payment date:
missing payment evidence must not be silently treated as payment.

The optional `CooperativeIbsCbsFacts` preserves separate observations for
the **specific** IBS/CBS cooperative election under LC 214/2025 art. 271:
formal election, submission of the required associates list, independently
confirmed option, effect-from date, direction of supply, association at the
event, and recipient's regular-regime status where relevant. Both the
cooperative-as-provider and cooperative-as-taker situations may occur.
A submitted form **without the associates list** is not proof of an effective
election. The independently confirmed election requires evidence of both
steps; missing evidence is not a false or zero value. A verified election
alone does not establish that an individual service qualifies for reduced
rates. The scope and personal-use exceptions in Decree 12.955/2026 art. 392
and CGIBS Resolution 6/2026 art. 392 are separate nullable facts: an
unverified purpose or consumption status is not assumed to qualify.
Other art. 271 exceptions (including participating cooperatives and
specific material goods) need their own legally verified operation facts.
For an associate supplying to the cooperative while not subject to the
regular regime, LC 214/2025 art. 271(I) and the broader exclusion in the
regulatory art. 392(II) require a separate legal reconciliation before a
consumer may assert zero rates; this DTO deliberately does not decide it.
The separate municipal ISS `regEspTrib` code does not establish the IBS/CBS
cooperative election. Never put real member identifiers or option receipts
into synthetic fixtures, logs, or versioned research.

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

A rule's legal applicability is evaluated on the legally relevant
transaction date: event date when the act applies to the taxable event,
competence when a service or municipal condition is competence-scoped,
and actual payment date for obligations expressly triggered by payment.
Different dates must not be silently replaced with invoice issuance date,
system time or transmission time. If the controlling date or its evidence is
unknown, the decision is `unverifiable`, not an inferred exemption.
Protocol compatibility is evaluated **separately** against the
environment-specific active schema and deployment. A newly published NT/XSD
does not automatically become the active production emitter. A successful
gateway response does not retroactively make an incomplete obligation legal.

## Outcomes and ownership

The permitted decision categories are `normative`, `calculation`,
`document` and `protocol`; statuses are `pass`, `fail`,
`not_applicable`, `unverifiable` and `unsupported`; severity is
`block`, `warn` or `info`. An empty assessment means no explicit
assessment outcome was provided; it is **not** implicit permission to emit.
An explicit `not_applicable` outcome is distinct from a rule that was never
evaluated. A consumer must not equate `unverifiable` with `pass`, nor
assume every unavailable advisory municipal query is a fiscal rejection. Where a demonstrably mandatory
requirement cannot be satisfied, the emission preflight blocks at the common
last boundary before the fiscal POST.

The module owns context adaptation, statutory obligation and monetary-rule
assessment. `nfse-php` owns active versioned XML serialization, signature,
transport, XML schema validation and faithful DANFSe rendering from authorized
data. Previously authorized artifacts stay immutable. Actual rule strategies,
test scenarios and rollout decisions live in their respective implementation
work; this ADR deliberately defines no generic rule engine, DSL or arbitrary
database-configured tax formulas.
