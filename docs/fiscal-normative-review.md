<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Revisão fiscal independente — matriz de aceite

Este documento define **como verificar** as hipóteses fiscais das operações
efetivamente suportadas. Não equivale a parecer jurídico, homologação contábil,
autorização municipal nem confirmação da ativação do esquema XSD.

## Critério por operação

Registrar identificador estável, hipótese, regime, categoria suportada, serviço,
município de incidência, identidade do pagador, data do fato gerador, competência,
data do pagamento quando pertinente, ato aplicável, versão e vigência. Para cada
tributo separar obrigação própria, retenção efetiva, destaque documental,
estimativa transparente e recolhimento próprio. Fato ausente permanece
`unverifiable`; não transformar ausência em zero ou permissão.

A avaliação deve referenciar o resultado concreto de um teste sintético e seu
oráculo independente. CI verde não substitui deliberação profissional.

| ID | Contraprova | Resultado esperado | Responsabilidade |
| --- | --- | --- | --- |
| NF-ISS-01 | ADN municipal ausente, 404 ou desatualizado | Sem rejeição ou autorização municipal presumida | #424 |
| NF-ISS-02 | E0176–E0178 com regime especial e competência específica | Conferir regra literal e parâmetros municipais da versão vigente | #424 |
| NF-COOP-01 | Associado não regular fornece serviço à cooperativa | `unverifiable` até conciliação normativa; nunca zero automático | #427 |
| NF-COOP-02 | Opção não comprovada ou tomador cooperativo não verificado | `unverifiable`; não aplicar benefício | #427 |
| NF-COOP-03 | Associado não regular: distinguir IBS e CBS na operação de fornecimento à cooperativa | Registrar separadamente a dúvida legal de cada tributo; não conferir alíquota zero por analogia | #427 |
| NF-RET-01 | Tributos PIS/COFINS próprios sem retenção real | Não construir `vRetCSLL` a partir de tributo devido | #428 |
| NF-RET-02 | Retenção configurada sem valores confirmados | Não inventar retenção nem converter ausência em zero | #428 |
| NF-TEMP-01 | Fato gerador anterior à obrigação e transmissão posterior | Usar data legalmente relevante; não data de POST | #361 |
| NF-TEMP-02 | SEFIN aceita ausência IBS/CBS em 2026 | Aceite técnico não demonstra obrigação cumprida | #361 |
| NF-PROT-01 | NT009 publicada mas sem ativação comprovada no ambiente | Não alterar emissor ativo | nfse-php#121 |
| NF-SCOPE-01 | Operação RTC não-LC116 | Reportar `unsupported`, sem emissão como LC116 | #432 |

## Evidência primária

- Portal de produção, Anexo I v1.01 de 09/02/2026:
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- NT007/NT008/NT009 e anexos VI/VII:
  https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc
- LC 116/2003:
  https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm
- LC 123/2006:
  https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp123.htm
- LC 214/2025 consolidada:
  https://www2.camara.leg.br/legin/fed/leicom/2025/leicomplementar-214-16-janeiro-2025-796905-normaatualizada-pl.html
- Decreto 12.955/2026 consolidado (**CBS**, não extensão automática ao IBS):
  https://www2.camara.leg.br/legin/fed/decret/2026/decreto-12955-29-abril-2026-799019-normaatualizada-pe.html
- Resolução CGIBS 6/2026, regulamentação do **IBS**, arts. 391–393:
  https://www.cgibs.gov.br/upload/arquivos/202604/30084927-res-cgibs-n-6-30-abr-2026-regulamenta-o-ibs.pdf
- Comunicado CGNFS-e sobre 2026:
  https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-orienta-sobre-os-prazos-para%20destaque-de-ibs-cbs-nas-notas-fiscais-de-servico

O registro histórico na #427 informa SHA-256
`de5bc492959eadc8bfa7540e16939995924f2188f743648eaf84d3b31e9eeb7c`
para uma cópia de Anexo I e linhas E0177=234, E0176=235, E0178=236
em `xl/worksheets/sheet5.xml`. **Ainda é necessário recuperar novamente
o binário do endereço governamental e confrontar o hash e o texto integral**.
Não usar a versão antiga de E0176 como restrição municipal universal.

## Implantação da NT009 por ambiente — referência de 27/10/2026

A publicação da NT009 v1.01 não ativa automaticamente um leiaute em ambos os ambientes. O comunicado do CGNFS-e de 20/10/2026 registra somente em **produção restrita** a primeira etapa: reposicionamento de `CST` e `cClassTrib`, códigos 99.02–99.04 e `vAjusteBC`. Em **produção**, o leiaute da NT004 v2.0 permanece até 30/11/2026; implantação prevista para 01/12/2026 requer nova confirmação antes da ativação.

Evidências oficiais:

- https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-informa-disponibilizadas-no-ambiente-de-testes-o-release-1-das-evolucoes-da-nota-tecnica-009
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/atualizacoes-e-implantacoes
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita

**Evidência ainda não obtida:** o download binário de `gov.br` retornou HTTP 403 no ambiente de auditoria. Os SHA-256 dos anexos de produção restrita e o confronto literal integral de E0176–E0178 com os anexos atuais continuam sem verificação independente. Não converter versões ou hashes históricos em atestado atual.

## Controvérsia cooperativa a ser revisada

Para o fornecimento de serviço do associado à cooperativa, a LC 214/2025,
art. 271, I, não explicita no próprio inciso uma exigência de regime regular
do associado. Já o art. 392, II, do Decreto 12.955/2026 (CBS) e o art. 392,
II, da Resolução CGIBS 6/2026 (IBS) contêm exclusões relativas a associados
não sujeitos ao regime regular. Isso requer análise **individual** de IBS e
CBS, da hierarquia normativa, da hipótese concreta e de eventuais alterações
normativas na data do fato gerador; não basta citar apenas o decreto federal.

O avaliador existente retorna `unverifiable` nessa hipótese e não aplica
alíquota zero automaticamente. A revisão jurídico-contábil deve registrar,
para **cada tributo**, qual dispositivo prevalece, por quê, qual condição é
necessária, quais evidências comprovam a condição e qual consequência haveria
para uma operação suportada. Até esse parecer, a decisão permanece
inconclusiva. O documento não contém parecer assinado.

## Critérios para homologação

1. Matriz por operação suportada e fonte primária com vigência e dados de origem.
2. Confronto textual e binário das regras municipais de produção.
3. Resultados de contraprovas reproduzidos, não mera contagem de fixtures.
4. Comprovação da cobertura manual/automática e da fronteira do POST.
5. Revisão profissional identificável, com data, assinatura e ressalvas
   genuínas. Até lá, decisão permanece pendente de parecer.

O `MunicipalEmissionPreflight` só define admissibilidade da tentativa
municipal; `can_attempt` não é conformidade tributária integral.
`IssueInvoiceFiscalGroup` rejeita modalidades RTC não-LC116. O calendário
jurídico e a implantação dos XSDs devem permanecer separados.