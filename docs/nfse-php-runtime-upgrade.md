<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Atualização do runtime nfse-php

O módulo usa `librecodeoop/nfse-php` sob `3rdparty/`, isolado pelo
PHP-Scoper. A revisão pretendida é definida em `3rdparty/composer.json`,
e a revisão instalada é registrada no lock local do mesmo diretório. A
versão do Akaunting e o `composer.json` da aplicação não substituem esse
contrato.

## Procedimento de atualização

Antes da mudança, revise o PR do módulo, os testes de integração, o backup
de arquivos/banco e o plano de retorno. Realize o procedimento primeiro em
homologação, com certificado e ambiente próprios. Pare os workers de fila
e coloque a aplicação em manutenção durante a troca de código.

Na instalação existente, ajuste o caminho do módulo ao seu ambiente:

```bash
cd /var/www/html/modules/Nfse
git fetch origin main
git pull --ff-only origin main

# Atualize a revisão do pacote e o lock local (não versionado).
composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
composer --working-dir=3rdparty update librecodeoop/nfse-php --with-all-dependencies --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# Atualize as ferramentas de build e regenere a dependência isolada.
composer runtime-tools:install
composer thirdparty:scope

# Confira o commit realmente instalado pelo Composer.
composer --working-dir=3rdparty show librecodeoop/nfse-php --format=json
```

Compare `source.reference` do comando final com a referência fixada
em `3rdparty/composer.json`. Se divergirem, não retome a emissão:
investigue e resolva o lock/cache local. Alterar apenas o manifesto
não recompila `3rdparty/scoped/`.

Na raiz da aplicação, depois de verificar o ambiente:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Reinicie o PHP-FPM e os workers pelo serviço/contêiner apropriado e
valide o painel, a montagem da DPS, consultas, resposta autorizada,
recuperação de tentativas ambíguas e geração de artefatos em homologação.
Retire a manutenção somente após os checks de saúde.

## Compatibilidade e retorno

Mudanças nos domínios e estruturas de pré-visualização da NT009 **não**
autorizam, por si só, a transmissão do novo leiaute. O emissor de produção
deve usar apenas o contrato efetivamente vigente e aceito pela SEFIN.
Verifique independentemente o XSD ativo, o ambiente e as condições fiscais
antes de habilitar um serializador diferente.

Para reverter, restaure o commit anterior **aprovado** do módulo e seu
conjunto de dependências, regenere `3rdparty/scoped/`, reinicie os
processos e revalide a aplicação. Guarde os SHAs e backups no registro
de implantação, não neste guia versionado. Não reverta migrações ou
retransmita DPS de resultado incerto automaticamente.

Consulte [filas e pós-emissão](queue.md) para o comportamento de workers
e recuperação.
