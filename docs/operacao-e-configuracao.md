<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Configuração e operação do módulo NFS-e

O módulo `akaunting-nfse` integra o [Akaunting](https://akaunting.com/) aos
serviços do Sistema Nacional NFS-e. Requer versão compatível do Akaunting
(ver `module.json` e metadados do pacote), PHP e extensões declarados nos
manifestos e acesso aos serviços nacionais aplicáveis.

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

## Atualização e testes

Siga [Atualização do runtime nfse-php](nfse-php-runtime-upgrade.md).
Para desenvolver e verificar funcionalidades, utilize as suites e workflows
registrados no `composer.json`, `package.json` e
[documentação de testes](testing-ci-budget.md). Dados de teste precisam ser
sintéticos; nenhuma suíte comum deve transmitir notas fiscais reais.
