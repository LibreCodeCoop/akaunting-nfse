<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ambiente de desenvolvimento da NFS-e

O módulo NFS-e pode ser desenvolvido em qualquer ambiente compatível com o
Akaunting. O [ambiente Docker da LibreCode](https://github.com/LibreCodeCoop/akaunting-docker)
é uma opção, não uma dependência. Reutilize o ambiente de desenvolvimento já
disponível; não crie outra instância nem reinicialize dados compartilhados.

Antes de executar os testes, confirme a versão instalada do Core Akaunting,
a revisão do módulo, a referência da biblioteca `nfse-php` empacotada e
as dependências do Composer. Execute PHP, Artisan e Composer no ambiente
compatível com o runtime instalado, conforme sua documentação.

O comando `composer thirdparty:build:prod` recompila as dependências
isoladas do módulo quando necessário, por exemplo, após uma alteração da
versão fixada da biblioteca fiscal. Não execute atualizações adicionais
de dependências sem identificar a necessidade.

## Validação

Utilize as suítes existentes de PHPUnit, Behat e testes de API, seguindo os
workflows e a configuração de testes do repositório. Verifique os contratos
do módulo no ambiente de desenvolvimento, sem transmitir notas fiscais reais
como teste de funcionamento.

As configurações locais (`.env`, overrides do Compose), os certificados,
os segredos e os dados persistentes devem ser preservados. Mantenha os dados
de execução no diretório `volumes/` do projeto responsável pelo ambiente,
fora do versionamento. Nunca publique credenciais ou dados fiscais reais.

Para armazenamento de segredos, consulte a
[implantação opcional do OpenBao](openbao-deployment.md) e a
[configuração do módulo](operacao-e-configuracao.md).
