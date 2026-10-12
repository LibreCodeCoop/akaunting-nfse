<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Configuração e operação do módulo NFS-e

O módulo `akaunting-nfse` integra o [Akaunting](https://akaunting.com/) aos
serviços do Sistema Nacional NFS-e. Requer versão compatível do Akaunting,
PHP e extensões declaradas nos manifestos e acesso aos serviços nacionais
aplicáveis. O `module.json` atual não declara uma faixa de versões do Core;
confirme a compatibilidade da versão instalada com os testes e releases do módulo.

## Habilitação

Instale e habilite o módulo por um dos mecanismos suportados pelo seu ambiente
Akaunting. Nas configurações NFS-e, informe os dados do prestador, as opções
de operação, certificado digital e o secret store. Os caminhos e meios de
instalação dependem da hospedagem e não fazem parte do contrato do módulo.

## Certificados e segredos

Os recursos de proteção de certificado utilizam OpenBao ou HashiCorp Vault,
com KV v2. Configure URL, mount e credenciais pela interface prevista.
Prefira credenciais com escopo mínimo e ciclo de vida gerenciável, como AppRole
quando suportado. Não registre nem publique senha PFX, token, Role/Secret ID
ou chave privada. Não envie esses valores em anexos de issues ou logs.

## Dados fiscais e validação

Associe perfil fiscal a cada serviço/fatura conforme os dados da operação.
A classificação dos impostos federais dos itens considera nomes e
identificadores textuais configurados no Akaunting: PIS, COFINS, IRRF, CSLL e
contribuição previdenciária. Use nomes explícitos, por exemplo `IRRF - Serviços`
ou `cod:pis`; códigos numéricos isolados podem ser ambíguos.
Confira o enquadramento tributário com o responsável fiscal.

Na aba fiscal de cada empresa, indique a opção pelo Simples Nacional, o
**regime especial de tributação** da DPS e, para ME/EPP, o **regime de apuração
tributária do Simples Nacional** (`regApTribSN`). Os valores desse último campo
são `1` (tributos federais e ISSQN pelo Simples), `2` (federais pelo Simples,
ISSQN fora) e `3` (ambos fora). A escolha não é deduzida da natureza jurídica:
depende do enquadramento fiscal e competência efetivos e deve ser confirmada
com o responsável tributário. Sem essa informação, a emissão ME/EPP será
bloqueada (E0166). Na opção `1`, informe `regEspTrib=0` (E0175). Alterar a
opção de Simples não apaga silenciosamente escolhas anteriores, mas a emissão
será revalidada. Regimes municipais especiais continuam sujeitos às condições
do município, prestador, serviço e competência (E0176–E0178).

IBS/CBS só deve ser habilitado quando a operação e o contrato de emissão
suportarem os campos requeridos. O módulo não deduz automaticamente códigos
CST, cClassTrib, indicadores da operação e demais condições fiscais.

Para detalhes, consulte [Emissão vigente](emissao-vigente.md) e
[Segurança fiscal](fiscal-safety.md).

## Fluxos e documentos

A NFS-e autorizada e a fatura Akaunting possuem estados distintos.
A emissão usa o XML autorizado como fonte fiscal para DANFSe e reconciliação.
O processamento pós-emissão pode utilizar a fila do Akaunting, com proteção
contra duplicação após autorizações ambíguas: [Filas](queue.md).

Documentos e eventos podem ser consultados pelo ADN conforme as permissões
da integração. O módulo não aplica automaticamente aos cadastros alterações
identificadas em consultas somente leitura.

Para dados fiscais de clientes, veja
[Perfil fiscal do tomador](contact-fiscal-profile.md); para relatórios e
consulta de registros por nota, utilize as interfaces próprias de NFS-e.

## Numeração fiscal da DPS — implementação em andamento

O épico #423 ainda não habilitou o sequenciamento unificado. As emissões
existentes **não utilizam** as tabelas de reserva novas. Não iniciar emissões
em produção presumindo que a arquitetura proposta já esteja integrada.

A fundação de reserva usa identidade fiscal do emissor (município de emissão,
tipo e registro federal), ambiente e série; `company_id` permanece apenas
como propriedade/autorização da operação. Novas séries começam **inativas**.
A referência de coordenação não garante unicidade entre bancos independentes:
é indispensável provar que outras instalações e emissores não disputem a
mesma série ou coordenem as reservas em armazenamento transacional comum.
Não cadastrar a referência com dados de clientes, credenciais ou certificados.

A fonte efetiva de números não pode ser o ID local da fatura, o ID de um
grupo ou o recibo cancelado. Qualquer operação iniciada que tenha resultado
ambíguo deve preservar sua DPS para reconciliação exclusivamente por leitura,
sem retransmissão automática. O número autorizado da NFS-e nunca é alterado
por essa política. Consulte o ADR 0003 e o épico #423 antes da ativação.

## Atualização e testes

Siga [Atualização do runtime nfse-php](nfse-php-runtime-upgrade.md).
Para desenvolver e verificar funcionalidades, utilize as suites e workflows
registrados no `composer.json`, `package.json` e
[documentação de testes](testing-ci-budget.md). Dados de teste precisam ser
sintéticos; nenhuma suíte comum deve transmitir notas fiscais reais.
