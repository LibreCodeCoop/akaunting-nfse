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

## Matriz para deliberação jurídico-contábil independente

A referência temporal deve ser selecionada pela hipótese: fato gerador,
competência do serviço ou pagamento efetivo, conforme o ato aplicável.
A coluna de testes identifica contraprovas disponíveis, **não** certificação
jurídica, conformidade integral de produção ou autorização da SEFIN.
Aprovação técnica de política e aceite profissional são registros distintos.

| Hipótese | Fonte e período | Contexto e resultado esperado | Evidência técnica / teste | Revisão profissional |
| --- | --- | --- | --- | --- |
| RTC-ORD | Ato Conjunto RFB/CGIBS 4/2026; 01/10/2026, ressalvadas categorias excepcionais | Serviço LC 116 ordinário; comparar fato gerador antes/depois, sem usar data do POST ou presença do XML como prova da obrigação | `RtcSupplyAssessmentPolicyTest`; `IndependentTemporalOracleTest` | Pendente: enquadramento e data legal |
| RTC-EXC | Ato 4/2026, art. 1º, III, b/c; 01/12/2026 | LC 116 01.03, 01.05, 01.09 e 16.01; não aplicar corte ordinário | Mesmos oráculos temporais | Pendente: classificação da prestação |
| RTC-SN | Ato 4/2026, art. 1º, § 1º; 01/01/2027 | MEI/Simples sujeitos às regras próprias; ausência de prova do regime não equivale a não optante | `RtcSupplyAssessmentPolicyTest`; `IndependentTemporalOracleTest` | Pendente: regime comprovado por competência |
| RTC-OUT | Ato 4/2026 e modalidade de operação; vigência conforme categoria | Locação, plataforma e outras operações não-LC116: não serializar como serviço LC116 sem suporte de protocolo | `RtcSupplyCategoryPolicyTest`; `RtcSupplyAssessmentPolicyTest` | Pendente: qualificação da operação |
| ISS-BASE | LC 116/2003 e legislação municipal na competência | ISS próprio e retido dependem de serviço, município de incidência, prestador e tomador; nenhum percentual genérico | `IndependentFinancialOracleTest`; `MunicipalIncidenceResolverTest` | Pendente: lei/parametrização do município concreto |
| ISS-E0176 | Anexo I oficial por ambiente e competência | Autônomo: cadastro em município emissor ou de incidência ou admissão sem verificação pelo município de incidência, quando parametrizada | Anexos I confrontados; `MunicipalAdmissibilityEvaluatorTest`; `SpecialTaxRegimePolicyTest` | Pendente: prova municipal por prestador |
| ISS-E0177 | Anexo I oficial por ambiente e competência | Rejeição do regime **não admitido** pelo município de incidência; ausência de consulta não prova proibição | Anexos I confrontados; `MunicipalAdmissibilityEvaluatorTest` | Pendente: parâmetro municipal vigente |
| ISS-E0178 | Anexo I oficial por ambiente e competência | Vincular contribuinte, serviço e competência; autorização municipal pode restringir ou não códigos de tributação | Anexos I confrontados; `SpecialTaxRegimePolicyTest` | Pendente: autorização individual e código |
| REG-SN | Anexo I E0160/E0162/E0166 e E0172/E0174/E0175 na versão aplicável | `opSimpNac`, `regApTribSN` e `regEspTrib` são fatos distintos; código ISS não comprova opção IBS/CBS | `FiscalContext`; `SpecialTaxRegimePolicyTest` | Pendente: cadastro e regime por competência |
| FED-RET | Lei 10.833/2003, arts. 30–32, e normas do pagador; considerar evento de pagamento quando exigido | PIS, COFINS e CSLL retidos somente sob hipóteses verificadas; valor próprio não é valor efetivamente retido nem autorização de `vRetCSLL` | `IndependentFinancialOracleTest`; `FederalSocialRetentionCalculatorTest` | Pendente: serviço, pagador e dispensa concretos |
| FED-OWN | Leis 10.637/2002 e 10.833/2003, regime e período | Apuração própria PIS/COFINS separada da retenção e da configuração contábil; sem alíquota genérica | `FederalTaxSnapshotBuilderTest`; `IndependentFinancialOracleTest` | Pendente: regime, base e exceções |
| FED-IRRF | Lei 9.430/1996 e demais atos aplicáveis ao tipo de pagador/serviço | Não inferir IRRF de CPF/CNPJ, nome ou esfera pública genérica; verificar pagamento e retenção real | `IndependentFinancialOracleTest`; `InvoiceTaxFactsResolverTest` | Pendente: identificação do pagador e ato aplicável |
| TRANS-CONS | Lei 12.741/2012 e Decreto 8.264/2014; venda a consumidor final | Estimativa de transparência é distinta de tributo próprio e retenção; desconhecido não é zero | `ApproximateTaxSourceResolverTest`; `ApproximateTaxSourceFeatureTest` | Pendente: operação e dispensa aplicável |
| COOP-OPTION | LC 214/2025, art. 271, § 3º; Decreto 12.955/2026 e Resolução CGIBS 6/2026, art. 393; opção para 2027 | Exigir registro da opção, envio da lista e comprovação de efetividade; 31/10/2026 é o prazo comunicado, prorrogação anunciada em 29/10 ainda não formalizada | `CooperativeIbsCbsFactsTest`; `CooperativeIbsCbsEligibilityAssessorTest` | Pendente: recibos oficiais e decisão empresarial |
| COOP-IBS | LC 214/2025, art. 271, I, versus Resolução CGIBS 6/2026, art. 392, II; exame do ano de 2027 | Associado não regular fornece à cooperativa: `unverifiable`, sem zero presumido; separação da controvérsia do IBS | `CooperativeIbsCbsEligibilityAssessorTest`; `IndependentCooperativeCounterexamplesTest` | Pendente: parecer IBS e hierarquia normativa |
| COOP-CBS | LC 214/2025, art. 271, I, versus Decreto 12.955/2026, art. 392, II; exame do ano de 2027 | Mesma operação: `unverifiable`, sem extrapolar norma da CBS ao IBS | Mesmos testes, diagnóstico CBS específico | Pendente: parecer CBS e hierarquia normativa |
| FISC-HISTORY | ADR 0002 e atos aplicáveis à competência/evento | Preservar fatos, fonte, versão, decisão e XML autorizado sem recálculo retrospectivo por norma nova | `AuthorizedFiscalSnapshotExtractorTest`; contratos tipados; testes de integração separados | Pendente: auditoria integrada da persistência |
| NT009 | NT009 e comunicado oficial de 20/10/2026; produção restrita ativa para primeira etapa, produção planejada para 01/12/2026 | Separar publicação da NT, implantação por ambiente e obrigação legal; não ativar antecipadamente produção | Anexos e XSD recuperados; testes de contrato `NfsePhpCurrentIssuanceContractTest` | Pendente: confirmação do rollout real |

**Situação de aceite:** as evidências técnicas e a matriz estão preparadas
para revisão. A hipótese cooperativa controvertida não está juridicamente
homologada. A ausência de resposta ou de prova municipal não autoriza
classificar a emissão como legalmente correta nem inventar bloqueio SEFIN.
Uma aprovação independente deve identificar revisor, data, hipótese, fontes,
conclusão, ressalvas, contraprovas e assinatura; até lá, não fechar #427.

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

## Anexo I: conferência dos arquivos oficiais em 27/10/2026

Foram recuperados diretamente de `www.gov.br` os arquivos XLSX oficiais dos
ambientes de produção e produção restrita. O SHA-256 local de produção
(`de5bc492959eadc8bfa7540e16939995924f2188f743648eaf84d3b31e9eeb7c`)
confirma o hash histórico. O hash demonstra identidade do binário recuperado,
mas não substitui assinatura digital oficial ou prova de ativação da SEFIN.
As regras nacionais E0176, E0177 e E0178 foram confrontadas nos dois pacotes.
Sua aplicabilidade a uma DPS ainda depende de dados municipais específicos.

| Artefato oficial | SHA-256 conferido |
| --- | --- |
| [Anexo I produção 09/02/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/anexo_i-sefin_adn-dps_nfse-snnfse-v1-01-20260209.xlsx) | `de5bc492959eadc8bfa7540e16939995924f2188f743648eaf84d3b31e9eeb7c` |
| [Anexo I produção restrita 20/10/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/anexo_i-sefin_adn-dps_nfse-snnfse_prodrest-v1-01-20261020.xlsx) | `72b05981c8882cdd807f3acf901b669465360f9cf1b9d9e235ac4363dacfb2d8` |
| [XSD produção restrita 20/10/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/esquemas_prod_restrita_20261020.zip) | `25fdcecc651a67a216496ab10d7566631e6ed10f721e37d62c7845dadb0ea267` |
| [Anexo VIII consultivo 26/10/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/anexo_viii-tabela-correlacao-v01-02-00-20261026.xlsx) | `12cc80bc92687856b47e961b98cdbaa3f8cae568c099b3ef71994e73482ac7d0` |

No Anexo I de produção, E0177/E0176/E0178 estão nas linhas de regra
231/232/233 da aba `RN DPS_NFS-e`; em produção restrita, nas linhas
247/248/249 de `RN DPS_NFS-e 1.01`. E0176 distingue parametrização
do profissional autônomo no município emissor ou de incidência e
a admissão sem verificação na parametrização do município de incidência.
E0178 admite atribuição ao contribuinte com ou sem limitação por códigos
de tributação, segundo observação da versão restrita. Não presumir veto
universal apenas pelo código `regEspTrib`.

## Implantação da NT009 por ambiente — revisada em 29/10/2026

A publicação da NT009 v1.01 não ativa automaticamente um leiaute em ambos os ambientes. O comunicado de 20/10/2026 registra somente em **produção restrita** o release 1: reposicionamento de `CST` e `cClassTrib`, códigos 99.02–99.04 e `vAjusteBC`.

O [esclarecimento oficial de 29/10/2026](https://www.gov.br/nfse/pt-br/noticias/nfs-e-nacional-confira-oas-rientacoes-sobre-leiaute-em-producao-regras-de-preenchimento-de-ibs-cbs-e-status-da-nota-tecnica-009) confirma que:

- Em **produção**, o leiaute permanece na NT004, com o domínio pertinente de retenção PIS/COFINS da NT007. Não enviar antecipadamente XML da NT009.
- Em **produção restrita**, o release 1 da NT009 v1.0 está no emissor web e na API, mas operações 99.02–99.04 são disponibilizadas por **API**, não pelo formulário web específico ainda pendente.
- O release 2, com ajustes da NT009 v1.01 e posteriores, será disponibilizado primeiro em produção restrita; os releases 1 a 3 são **previstos** em produção para 01/12/2026, sem comprovação de ativação efetiva hoje.
- O ambiente restrito é de teste e não produz efeitos fiscais. Publicação da NT, obrigatoriedade legal e implantação XSD/SEFIN são decisões temporais distintas.

Evidências oficiais:

- https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-informa-disponibilizadas-no-ambiente-de-testes-o-release-1-das-evolucoes-da-nota-tecnica-009
- https://www.gov.br/nfse/pt-br/noticias/nfs-e-nacional-confira-oas-rientacoes-sobre-leiaute-em-producao-regras-de-preenchimento-de-ibs-cbs-e-status-da-nota-tecnica-009
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/atualizacoes-e-implantacoes
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita

**Evidência obtida em 27/10/2026:** os anexos/XSD oficiais indicados acima foram recuperados, seus SHA-256 calculados e E0176–E0178 confrontadas em ambos os Anexos I. Continuam sem prova a parametrização municipal de uma operação particular e a ativação em produção do leiaute NT009. O Anexo VIII consultivo não substitui validação fiscal normativa.

## Controvérsia cooperativa a ser revisada

Para o fornecimento de serviço do associado à cooperativa, a LC 214/2025,
art. 271, I, não explicita no próprio inciso uma exigência de regime regular
do associado. Já o art. 392, II, do Decreto 12.955/2026 (CBS) e o art. 392,
II, da Resolução CGIBS 6/2026 (IBS) contêm exclusões relativas a associados
não sujeitos ao regime regular. Isso requer análise **individual** de IBS e
CBS, da hierarquia normativa, da hipótese concreta e de eventuais alterações
normativas na data do fato gerador; não basta citar apenas o decreto federal.

O avaliador retorna `unverifiable` nessa hipótese e não aplica alíquota
zero automaticamente. O diagnóstico conserva **três fontes independentes**:
`COOP-ZERO-CONFLICT-LC271-I` (lei complementar),
`COOP-ZERO-CONFLICT-DEC392-II` (CBS) e
`COOP-ZERO-CONFLICT-CGIBS392-II` (IBS). Essa separação não implica três
incidências tributárias nem três bloqueios: registra a divergência das normas
sobre uma única hipótese concreta, sem alterar a emissão atual.

O [manual oficial da RFB](https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/publicacoes/manuais/reforma-tributaria-do-consumo/manual_cooperativas.pdf/view)
(versão 1.1, publicada em 15/09/2026) confirma que a opção para 2027 exige
**registro da opção e envio da listagem de associados**, e a orientação
[conjunta RFB/CGIBS](https://www.gov.br/fazenda/pt-br/assuntos/noticias/2026/setembro/receita-federal-e-comite-gestor-do-imposto-sobre-bens-e-servicos-divulgam-orientacao-conjunta)
fixa o prazo de 31/10/2026 para produzir efeitos em 01/01/2027.
Em [comunicado de 29/10/2026](https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/outubro/receita-federal-e-cgibs-discutem-ampliacao-do-prazo-de-opcao-pelo-regime-especifico-da-cbs-e-do-ibs-para-as-cooperativas-ate-30-de-novembro/),
a RFB e o CGIBS **informaram que estudam** uma prorrogação até
30/11/2026. A proposta ainda depende de formalização; não substituir o
prazo vigente por uma data apenas anunciada como possibilidade.
A natureza cooperativa, o Lucro Real ou o envio de somente uma dessas etapas
não comprovam opção efetiva nem autorizam redução a zero.

A revisão jurídico-contábil deverá registrar, para **cada tributo**, qual
dispositivo é aplicável, fundamento da interpretação, data, fatos requeridos
e ressalvas. Até esse parecer, a controvérsia permanece inconclusiva.
Este documento não contém aceite profissional assinado.

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
