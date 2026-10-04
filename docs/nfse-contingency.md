<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# National NFS-e contingency: implementation status

Issue: #183

Reviewed on 2026-10-04 against the current production contributor
documentation published by the Sistema Nacional NFS-e.

## Conclusion

The repository must **not implement an operator contingency flow yet**.

Federal rules recognize NFS-e contingency, but the current production technical
documentation does not specify enough contributor-facing protocol details for a
third-party ERP to generate the legally required contingency receipt and later
transmit it deterministically.

The safe implementation state is therefore: ordinary DPS emission/recovery
remains supported; an explicit "emit in contingency" action is intentionally
absent until the Sistema Nacional NFS-e publishes the missing technical
contract.

## Normative basis

The national fiscal-document regulation defines contingency as the situation in
which technical problems prevent transmitting a fiscal document to the
authorizer/receiver or obtaining the authorization response.

For DPS/NFS-e specifically, it requires a service receipt when technical
problems affect DPS generation/transmission, **according to the technical
documentation**, and states that the contingency procedure is to be disciplined
by that technical documentation.

This creates a normative requirement, but not an implementable wire/document
contract by itself.

Reference:
- https://legis.senado.gov.br/norma/43106091/publicacao/43105497
  (arts. 136-137)

## Current production contributor API

The current production contributor manual is:

- **Manual dos Contribuintes - Sistema Nacional NFS-e**
- **Guia para utilização das APIs do Emissor Público Nacional**
- production version published as v1.2 / October 2025
- current production-documentation index reviewed on 2026-10-04

Reference:
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf/view

The contributor API manual documents:

- municipal-parameter queries;
- `POST /nfse` receiving a DPS and synchronously generating/rejecting NFS-e;
- `GET /nfse/{chaveAcesso}`;
- DPS recovery through `GET /dps/{id}` and `HEAD /dps/{id}`;
- generic event registration and event queries.

It does **not** define a contributor contingency endpoint or a contingency
request flag.

The public DPS guidance also states that a DPS can be created by contributor
software and later converted into NFS-e, including scenarios without permanent
connectivity. That establishes the usefulness of locally durable DPS data, but
does not define the legally required contingency receipt contract.

Reference:
- https://www.gov.br/nfse/pt-br/saiba-mais/como-a-nfs-e-e-gerada/o-que-e-dps

## Missing information required before implementation

The following details are not specified sufficiently in the current production
contributor API documentation:

1. mandatory fields and exact layout of the service receipt required during
   NFS-e contingency;
2. required receipt identifier/numbering semantics;
3. whether receipt numbering must be coupled to DPS series/number;
4. mandatory timestamps and timezone semantics;
5. maximum transmission deadline after contingency creation;
6. whether the ordinary DPS sent later requires a contingency marker, special
   series, or other discriminator;
7. whether the later transmission uses ordinary `POST /nfse` unchanged;
8. explicit reconciliation rules between the local receipt and the later
   authorized NFS-e;
9. behavior when the transmission deadline expires;
10. printable/legal wording required on the contingency receipt.

Without these rules, implementing a printable "contingency receipt" would
require assumptions about legal/fiscal semantics rather than implementing a
published protocol.

## What remains safe today

The module may safely:

- persist ordinary invoice/fiscal inputs before emission;
- use deterministic DPS identifiers;
- recover ambiguous POST outcomes by DPS before attempting another emission;
- retry only safe reads;
- keep an audit trail of transport failures.

It must not:

- silently switch an invoice into contingency;
- invent a contingency series or flag;
- claim that an arbitrary local PDF is the legally required receipt;
- automatically send a delayed DPS under assumptions not present in the
  production contract.

## Revisit trigger

Reopen implementation when the production Sistema Nacional NFS-e technical
documentation publishes enough information to answer all of the following:

- exact contingency receipt layout/mandatory fields;
- numbering and timestamp rules;
- transmission deadline;
- later-transmission API/flag/series behavior;
- reconciliation semantics.

At that point the implementation should be derived from the published contract
and covered by deterministic tests before exposing an operator action.
