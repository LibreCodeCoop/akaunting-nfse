<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Municipal competence evidence contract (#374)

The national six-digit cTribNac catalog validates the classification, **not** administration by the municipality of ISS incidence. A nine-digit parameter service code is national six digits plus the three-digit municipal complement (default `000`). Neither a published rate nor HTTP 404 nor a catalog hit authorizes or rejects issuance.

## Two distinct surfaces

* The existing Akaunting item-list/edit badges remain a **today-only advisory preview**, using the provider municipality configured in settings. They do not establish the ISS incidence municipality of any future invoice and cannot be reused as invoice preflight.
* `ItemMunicipalValidationResolver::resolveMany(..., competence: 'YYYY-MM-DD')` retrieves only same-company, same-environment, same-municipality, same-nine-digit-code and **exact** competence snapshots. It never substitutes a different competence. It is advisory even when the official rate matches. No historical rows are deleted.
* `MunicipalAdmissibilityEvaluator::evaluate(context, evidence)` is a pure, serializable contract intended for later consumption by #376. It takes company ID, sandbox/production, **externally resolved ISS incidence** IBGE municipality, effective DPS competence, national code, complement, nine-digit service code and, for SEFIN evidence, exact DPS ID and SHA-256 digest. The current code has **no authoritative generic way to infer ISS incidence from provider municipality**, so a caller must resolve it under LC 116 article 3, including applicable exceptions, or leave it missing. It must not silently default to provider municipality.
* The output includes `decision` (`authorized|rejected|unverifiable`), `can_attempt`, `authorized`, `binding` (`exact|none`), `reason`, normalized context and provenance (`kind`, URL, HTTP status, consultation time, contract version/validity if known, fallback reason). An `authorized` outcome represents an **observed SEFIN issuance result for this DPS**, not municipal approval for any other DPS.
* A caller can supply `kind=sefin_issuance_result` only after verifying the actual authenticated SEFIN response. Exact context, SEFIN host, DPS digest and ID, consultation time, authoritative status and access key (successful issuance) or E0312 (specific rejection) are needed for a decisive result. All other cases are `unverifiable` and non-blocking. The module currently does **not** wire this contract into issuance: #376 must integrate it with validated gateway responses, avoid relying on caller-crafted evidence, and assess regime-specific applicability (including MEI) before issuing any preflight veto.

## Official basis and limits

* NFS-e National public technical documentation: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica. The issuer and municipal-parameter API manuals distinguish a parameter GET from SEFIN validation/authorization of a submitted DPS. Their versions and exact response schemas must be checked again before #376 wires the evaluator.
* LC 116/2003, article 3: https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm. ISS location is not invariably the provider address.
* SEFIN E0312 reported in #337 is a concrete emission rejection for the supplied DPS competence and municipality; it is not a universal municipality/code blacklist. Exact exception/MEI rules remain **unverified** and no inferred regime prohibition is implemented.
* ADN parameter responses can include only consultative rate, retention, convention and special-regime information, sometimes with 404 or partial payload. None of these is promoted to official municipal authorization or objective rejection. The existing snapshot schema predates this contract and does not persist HTTP status/version in dedicated columns; omitted metadata is expressly unknown rather than fabricated.

No nfse-php protocol change is needed for this preparatory module-level evaluation.
