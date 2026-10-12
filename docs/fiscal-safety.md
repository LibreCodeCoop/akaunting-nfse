<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Segurança fiscal na emissão NFS-e

Esta página reúne as garantias operacionais que importam ao operador e à
integração. O histórico de implementação e as tarefas futuras pertencem às
issues e aos pull requests, não a este contrato de uso.

## Autoridade e parametrização municipal

- Um código `cTribNac` existente no catálogo nacional não prova que a
  operação pode ser emitida para um município ou competência.
- A lista local de seleção LC 116 é resumida. Um subitem ausente dessa lista
  exige confirmação da classificação, mas não constitui prova de invalidade
  legal nem veto automático à tentativa de emissão. Um código nacional
  objetivamente inválido continua bloqueado pelo catálogo oficial.
- A parametrização municipal consultada no ADN (inclusive alíquotas, 404 ou
  cache) é **informativa**, não uma autorização ou rejeição fiscal.
- A identidade analisada inclui empresa, ambiente, município de incidência
  do ISS, competência da DPS, código nacional de seis dígitos e complemento
  municipal de três dígitos, quando aplicável. O município do prestador não
  pode ser presumido como local da incidência nas exceções da LC 116.
- Uma rejeição estruturada da SEFIN, como E0312, só é evidência para a DPS
  efetivamente enviada e identificada naquele contexto. Não se transforma
  em proibição genérica para outras operações.
- Dados faltantes, antigos ou de outra competência são **não verificáveis**.
  Não inventar autorização ou recusa quando a fonte não permite concluí-las.

Na configuração fiscal da empresa, o operador pode confirmar que o
município IBGE cadastrado corresponde ao **estabelecimento que efetivamente
presta o serviço**. Essa confirmação é registrada com CNPJ, município, data e
identificação do operador e deixa de ser aplicável se a identidade fiscal
configurada mudar. Ela permite selecionar parâmetros municipais consultivos
pela regra geral do art. 3º da LC 116/2003, **não** demonstra que a SEFIN
aceitará a DPS. Serviços sujeitos às exceções territoriais do art. 3º exigem
avaliação específica e continuam não verificáveis sem fatos fiscais adequados.
Quando não há confirmação, a consulta é inconclusiva, nunca uma rejeição
presumida. A aptidão para tentar a emissão não equivale a autorização.

A emissão autorizada só é confirmada pelo resultado oficial da SEFIN ou por
conciliação da mesma DPS, nunca pelo sucesso de uma consulta consultiva.

Referências: [LC 116/2003, art. 3º](https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm)
e [Manual do Emissor Público Nacional](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual).
As regras específicas de implantação devem ser conferidas na documentação
oficial vigente para o ambiente da operação.

Para a origem e a distinção entre tributos efetivos e informativos, consulte
[fontes versionadas de tributos aproximados](approximate-tax-sources.md).

## Emissão, histórico e recuperação

- Cada DPS tem identidade fiscal determinística. O histórico de tentativas
  registra o resultado e informações diagnósticas sem criar recibos fictícios.
- Recibos e XMLs autorizados têm fonte de verdade própria. Uma tentativa
  rejeitada ou ambígua não equivale a uma nota fiscal emitida.
- Uma resposta incompleta, timeout ou erro após `POST /nfse` pode significar
  emissão concluída sem confirmação local. Nessa situação, primeiro recuperar
  por identificação de DPS e chave de acesso, **sem repetir o POST às cegas**.
- Apenas uma resposta oficial inequívoca caracteriza rejeição. Falhas de
  WebDAV, DANFSe, email ou fila, posteriores à autorização, não devem
  transformar uma NFS-e emitida em tentativa não emitida.
- O histórico por empresa deve respeitar autorização, minimização dos dados
  pessoais e retenção fiscal/LGPD. Certificados, tokens, XML bruto e payload
  completo não devem ser copiados para diagnósticos de falha.

Para operar filas e recuperar artefatos após emissão, consulte
[processamento e filas](queue.md).

## Contingência

A geração de um recibo local com valor jurídico de contingência **não é
implementada** apenas com base na existência de falha de comunicação. Um
protocolo de contingência requer regras oficiais específicas para layout,
numeração, prazo de transmissão posterior e conciliação.

Enquanto o [contrato oficial do contribuinte](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual)
não estabelecer essas regras de modo aplicável à operação, não use PDF
arbitrário, série inventada ou um novo POST automático como substitutos
de autorização fiscal. A recuperação segura da DPS continua disponível.

A norma de contingência está prevista nos
[arts. 136–137 da regulamentação nacional](https://legis.senado.gov.br/norma/43106091/publicacao/43105497);
a implementação técnica depende da documentação pertinente.
