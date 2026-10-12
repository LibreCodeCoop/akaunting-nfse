<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# RTC supply categories and the 2026–2027 NFS-e document calendar

## Purpose and authority

An NFS-e *document-information* start date is not a tax rate, payable tax
amount, exemption, municipal ISS incidence, or evidence that an XML schema has
been activated by production SEFIN. Classification is a fact of the **supply
operation**, not a merchant name, product label, standalone LC 116 code,
taxpayer's legal form, or a successful gateway response.

Primary normative source: [Ato Conjunto RFB/CGIBS 4/2026, art. 1,
III, a–h, and §1](https://www.cgibs.gov.br/upload/arquivos/202607/31091735-20260730-16h30-ato-conjunto-rfb-cgibs-na-c2-ba-4-260731-090909.pdf),
signed 30 July 2026 and published 31 July 2026.
The [CGNFS-e clarification of 7 August
2026](https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-orienta-sobre-os-prazos-para%20destaque-de-ibs-cbs-nas-notas-fiscais-de-servico)
identifies the affected NFS-e categories and states that the national system's
non-rejection of missing IBS/CBS through 31 December 2026 is **not** proof
of legal compliance. Use the currently consolidated LC 214/2025,
LC 227/2026 and the appropriate Simples regulation to assess payable
IBS/CBS, reductions, exclusions, credits and election effects separately.

## Operation calendar: original legal start for taxable events

| Explicit operation category | Art. 1, III | Initial taxable-event date |
| --- | --- | --- |
| `ordinary_lc116` (ISS service not falling under a–c) | d | 2026-10-01 |
| `ordinary_lc116` with LC 116 subitem 1.03, 1.05 or 1.09 | b | 2026-12-01 |
| `ordinary_lc116` with LC 116 subitem 16.01 | c | 2026-12-01 |
| `digital_platform` (only supplies/intermediation within art. 22 conditions) | a | 2026-12-01 |
| `non_iss_intangible` (outside LC 116 and outside ICMS) | e | 2026-12-01 |
| `condominium_revenue` (specified condominium receipts) | f | 2026-12-01 |
| `lease` (movables, immovable leases/cessions; except situations in d) | g | 2026-12-01 |
| `residual_service` (service supplies not previously covered) | h | 2026-12-01 |

Art. 1 §1 defers the DFe obligation to **2027-01-01** for taxpayers
*verified* as opting for Simples Nacional; the obligation calendar must
not be confused with a separate IBS/CBS payment-regime election, or
with the operation's eligibility for a special cooperative regime.
Observe the actual taxpayer facts and election effective dates.
The set of cases covered by each statutory category remains narrower
than a product/industry-wide label. In particular, not every digital
marketplace transaction falls under art. 22, nor does every rental override
the exclusion of the relevant ISS-service classification in letter d.

## Domain and consumer contract

The existing `nfse_item_fiscal_profiles.rtc_supply_category` is
company-scoped; the existing group builder separates distinct categories
within a single financial invoice. Where the same catalog item can be
supplied through legally different operations, the recorded item-profile
category is only a **default/starting observation**: the emission
consumer must obtain a verified category for the actual transaction,
taker, municipality and date, rather than assuming all sales of that item
are equivalent. A blank/unknown category is `unverifiable`, never an
automatic `ordinary_lc116` in the new typed assessment.

`RtcSupplyAssessmentPolicy::schedule(FiscalContext)` uses
`taxableEventDate` to select the Ato 4/2026 DFe calendar. It returns a
nullable `required` (unknown is **not** `false`), source provision,
effective date and reason. `assess(FiscalContext)` returns
`FiscalAssessment` with evidence-bearing `FiscalDecision` entries.
If the calendar has started, the outcome remains `unverifiable` until a
separate evaluator checks actual IBS/CBS information against the legally
applicable document requirement. The typed policy **does not** silently
treat that requirement as fulfilled. If the calendar has not started, the
calendar-only result is `not_applicable`, not proof of a blanket fiscal
exemption.

For the current LC 116 DPS serializer, non-LC116 operation modalities
produce a separate `protocol/unsupported` decision at all dates,
including dates prior to the documentary obligation. A valid published
NT009 or XSD does not establish activation of a transmitter in either
production or restricted-production environment. The `nfse-php`
package owns transport, signature, schema and XML implementation;
the module owns legal operation facts and fiscal assessment.
Keep [the portal's RTC documentation and
activation notices](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc)
separate from legal dates; NT009 v1.01 and published table annexes
must not be mistaken for a verified deployable payload.

A date of invoice issuance or its service competence must **not**
silently stand in for the taxable-event date. For real NFS-e obligations,
the fiscal operator must provide an authoritative event date (or
an explicitly justified equivalence); when it is unavailable,
`taxable_event_date_unverifiable` is returned.
The profile's observed `opcaoSimplesNacional` may be 1, 2, 3 or
unknown. No global taxpayer default, company name or sales description
supplies unknown facts.

## Safe adoption and unresolved boundaries

The policy deliberately has no fiscal POST side effects.
The existing application continues to reject recognized unsupported
non-LC116 modalities before LC116 grouped issuance. The common,
last-mutable-boundary enforcement, evidence aggregation across *all*
manual, automatic, batch, substitution, re-emission and recovery paths
remains owned by **#429**. Until that integration exists, a clean result
from an older `IbsCbsEmissionReadiness` invocation is not proof that
the typed legal assessment ran, nor that a gateway which accepted XML
without IBS/CBS proved legal conformity.

Open verification for the final integration:

- authoritative taxable-event date versus fatura `issued_at` / competence;
- platform scope under LC 214 art. 22 §§ 1 and 12–14;
- categories e/g exceptions and applicable municipal/ISS jurisdiction;
- verified Simples and any different IBS/CBS payment election;
- item/category override for one specific operation with company isolation;
- independently calculated payable/due/withheld amounts (#428/#432);
- active XSD + transport capability by environment (`nfse-php` #121);
- fake SEFIN accepting an incomplete document without hiding legal diagnostics;
- no mutation of authorized NFS-e, tax history or archived PDF/XML.

No real taxpayer records, client certificates, production API requests
or extra XML emission are used by this domain assessment.
