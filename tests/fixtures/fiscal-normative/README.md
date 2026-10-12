<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Independent conditional tax audit corpus (#432)

These are **synthetic reference facts**, recovered from the 2026-10-12 #427
audit. They are **not** operational permission to issue fiscal documents,
an approved tax engine, a validated municipal rate, an activated XML schema,
or a legal/accounting certification. JSON source content was retained, with
only a final newline normalized. The verifier gained SPDX headers.

| Corpus | Cases | Purpose |
| --- | ---: | --- |
| `nfse_427_oraculos_sinteticos_2026-10-12.json` | 25 | Conditional financial/retention/ISS/disclosure hypotheses; 31 independently calculated monetary expectations |
| `nfse_427_matriz_temporal_sintetica_2026-10-12.json` | 20 | Taxable-event RTC applicability, separate from operational XSD deployment |
| `nfse_427_cooperativas_casos_a_revisar.json` | 6 | Cooperative counterexamples, **review proposals only** |

A separate set of **21 cooperative scenarios** was mentioned in the #442
audit but has **not** been recovered here as a complete, individually
versioned and re-executed corpus. Do not treat these six records as the 21.

## Run

```sh
python3 tests/fixtures/fiscal-normative/verify_nfse_427_independent.py
vendor-bin/phpunit/vendor/bin/phpunit -c phpunit.xml tests/Unit/Fiscal
```

The Python verifier uses only the standard library and `decimal.Decimal`
with `ROUND_HALF_EVEN`; it does not call the production calculator.
The PHPUnit suite separately tests production policy boundaries against
the fixed temporal expectations. Review any discrepancy against official
rules before updating a case; do not change an oracle to make CI green.

## Evidence boundaries

* Financial cases are **conditional** on the operation, taxpayer, rate,
  verified payment and competent jurisdiction. `IBS-2027` is conditional
  arithmetic, not an automatic CBS/IBS rate for 2027.
* `RTC` cases record **taxable-event date**, whereas current product inputs
  may use an invoice competence date. Equality must be proven in the adapter;
  a date mismatch must not be silently accepted.
* The official CGNFS-e guidance of 2026-08-07 permits **technical
  non-rejection** of omitted IBS/CBS through 2026-12-31; this is not a
  declaration of legal compliance.
* The official NT009 v1.01 publication of 2026-10-01 and the publication of
  a deployment *plan* do not confirm the active XSD in production or restricted
  production. The corpus must not activate or transmit new XML by itself.
* The six cooperative records do not prove effective election,
  satisfaction of cooperative-purpose exclusions, or an unconditional zero
  rate. They do not resolve LC 214 art. 271 versus regulatory art. 392.
* The package does **not** yet prove all XML/DANFSe diffs, municipal
  eligibility, end-to-end retention and accounting persistence. Those require
  separate real-feature/fake-transport tests and independent legal review.

## Sources and provenance

* [Ato Conjunto RFB/CGIBS 4/2026](https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/programas-e-atividades/reforma-tributaria-do-consumo/legislacao/atos-conjuntos/)
* [CGNFS-e 2026-08-07 interpretation](https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-orienta-sobre-os-prazos-para%20destaque-de-ibs-cbs-nas-notas-fiscais-de-servico)
* [NT007, NT008, NT009 v1.01](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc)
* [LC 214/2025 consolidated](https://www2.camara.leg.br/legin/fed/leicom/2025/leicomplementar-214-16-janeiro-2025-796905-normaatualizada-pl.html)
* Individual rule/authority URLs are recorded in each source catalog.
* Original audit summary: `akaunting-nfse#427`, PRs `#434` and `#442`.

No real company, tax credentials, real signing certificates, production endpoint
or network transmission is needed.
