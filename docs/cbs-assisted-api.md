<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Apuração assistida da CBS: contratos de consulta e limites

## O que está disponível

O módulo registra evidências de IBS/CBS de NFS-e emitidas a partir de XMLs
autorizados e apresenta, na revisão de notas recebidas pelo ADN, candidatos à
investigação de crédito. A ausência de campo não é convertida em zero. A nota
recebida, inclusive quando importada como fatura de compra, não prova
apropriação de crédito.

As classes puras CbsAssistedV2Protocol, CbsAssistedV2IncrementalReader e
CbsAssistedV2Journal oferecem um contrato determinístico para integrar
posteriormente as consultas oficiais de débitos, créditos, pagamentos e
recolhimentos da CBS. Elas não executam requisições HTTP e não aceitam que a
presença de um arquivo JSON, por si só, prove a autenticidade da Receita.
Não constituem nova calculadora de impostos.

## Protocolo oficial da CBS

Fontes primárias (consultadas em novembro de 2026):

- Documentação geral: https://docs.receitafederal.gov.br/apuracao-cbs/
- Fluxo assíncrono v1.1, 14/10/2026:
  https://docs.receitafederal.gov.br/apuracao-cbs/guiaapiassincrona.html
- Débitos v1.2, 14/10/2026:
  https://docs.receitafederal.gov.br/apuracao-cbs/debito.html
- Créditos v1.2, 14/10/2026:
  https://docs.receitafederal.gov.br/apuracao-cbs/credito.html
- Pagamentos v1.2, 14/10/2026:
  https://docs.receitafederal.gov.br/apuracao-cbs/pagamentos.html
- Recolhimentos v1.2, 14/10/2026:
  https://docs.receitafederal.gov.br/apuracao-cbs/recolhimentos.html
- Consulta de situação:
  https://docs.receitafederal.gov.br/apuracao-cbs/situacao-solicitacao.html
- Link temporário:
  https://docs.receitafederal.gov.br/apuracao-cbs/download.html

A integração é assíncrona: OAuth2 Client Credentials obtido em canal
autorizado, POST para solicitar uma consulta por CNPJ base com oito dígitos,
tíquete retornado (201), consulta de situação, link temporário e download
do JSON. Sem webhook, o campo urlRetorno é a string vazia. Não é uma API de
declaração ou uma autorização para emitir DARF automaticamente.

**Condições obrigatórias antes de habilitar uma sincronização real:**

1. Credenciais por estabelecimento matriz e permissões de acesso devidamente
   comprovadas, em armazenamento de segredos; nunca expor tokens no front-end.
2. Identidade da empresa e do ambiente explicitamente selecionados. Não
   reutilizar tokens entre empresas ou presumir que a base do CNPJ comprova
   autorização de acesso.
3. Journal durável dos tíquetes e tentativas, limitado a quatro novas
   solicitações por dia, por credencial e API. Não repetir POST em caso de
   timeout de resultado desconhecido. Consultar situação existente.
4. Download isolado da URL assinada, sem Bearer, sem redirects arbitrários,
   com limites de bytes, validação TLS, expiração e proteção SSRF. Não registrar
   URL assinada em logs nem enviar para o navegador.
5. Persistência transacional e isolada por empresa, ambiente, espécie
   (débito/crédito/pagamento/recolhimento), consulta e documento. Guardar
   proveniência, arquivo de origem autenticado, hash, timestamps e histórico
   de correções. Não misturar respostas de ambientes.
6. Atualizações incrementais: janela de até oito dias e base vinculada à
   última consulta concluída. Nunca somar isoladamente um arquivo de retorno
   como se fosse o saldo completo de uma competência.
7. Reconciliação por chave e tipo de documento com NFS-e própria/recebida e
   outros DF-e aplicáveis; documentos sem chave ou com dados conflitantes vão
   para revisão manual. Preservar valores oficiais separados dos valores de
   XML e dos lançamentos contábeis da fatura.
8. Controle de recursos do serviço fiscal, tratamento de 401/403/429, webhook
   restrito e autenticação independente da notificação; nenhum replay anônimo
   pode alterar saldo.

Os contratos puros do módulo validam tipos, CNPJ, tíquete, estrutura temporal,
valores exatos em centavos, identidades por documento e substituições
incrementais. Enquanto a autenticação e a persistência acima não forem
configuradas e verificadas em ambiente apropriado, a sincronização é
**indisponível**. Não cadastrar valores sintéticos como dados de produção.

## Apuração e contexto jurídico

Em 2026, a Plataforma da CBS trabalha em ambiente beta com simulações,
sem efeitos fiscais reais para os débitos e créditos simulados. A partir
de 2027, efetividade, isenções, créditos e valores devidos dependem de regra
vigente e da informação da plataforma correspondente. Não transportar saldos
simulados para contabilidade real.

CbsAssistedV2IncrementalReader preserva os valores apurado, saldoDevedor
ou saldoCredor efetivamente presentes na estrutura oficial, sem presumir
uma fórmula de débito líquido e sem tratar crédito apresentado como crédito
apropriado. Para pagamentos/recolhimentos, preserva as composições do DARF,
sem interpretá-las como quitação de uma fatura comercial.

CbsAssistedV2Journal compara revisões da mesma chave em função do
timestamp informado; revisões anteriores não sobrescrevem valores mais novos,
revisões idênticas são idempotentes e revisões contraditórias no mesmo instante
permanecem em conflito. A classe não grava dados: a fronteira do banco
de dados e a confirmação da proveniência pertencem ao adaptador de
sincronização antes de sua ativação. Consultas sem eventos não provam ausência
de débitos ou conformidade fiscal.

Para IBS, confirmar e implementar o contrato oficial e o ambiente próprio do
CGIBS separadamente. A interface da CBS não constitui prova de compatibilidade
nem substitui a apuração assistida do IBS.

Para NFS-e, os contratos de emissão devem usar apenas a versão do leiaute
implantada no ambiente correspondente. NT009 em produção restrita não é
habilitação automática em produção. Os quadros de IBS/CBS no fechamento e
no ADN são evidências documentais, não transmissão de obrigação acessória,
DeRE, opção pelo regime cooperativo ou parecer fiscal conclusivo.

## Testes seguros

No runtime PHPUnit do módulo:

- tests/Unit/Application/CbsAssistedV2ProtocolTest.php
- tests/Unit/Application/CbsAssistedV2IncrementalReaderTest.php
- tests/Unit/Application/CbsAssistedV2JournalTest.php
- tests/Unit/Application/IbsCbsEvidenceReportTest.php
- tests/Unit/Application/IbsCbsReceivedEvidenceTest.php

Todos usam dados fictícios e não abrem conexão com a Receita. Validação
de ponta a ponta com credenciais e armazenamento isolados continua uma etapa
de implantação separada.
