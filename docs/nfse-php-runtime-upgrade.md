<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Atualização do runtime nfse-php

O módulo utiliza `librecodeoop/nfse-php` em `3rdparty/`, isolado pelo
PHP-Scoper em `3rdparty/scoped/`. A revisão esperada é declarada em
`3rdparty/composer.json`. O `composer.lock` desse diretório é local e não
versionado; confirme sua compatibilidade em cada ambiente.

## Atualização

Com os backups conferidos, suspenda emissões e workers. Atualize o código
do módulo no host e, dentro do contêiner, no diretório
`/var/www/html/modules/Nfse`, execute:

```bash
composer thirdparty:build:prod
composer --working-dir=3rdparty show librecodeoop/nfse-php --format=json
```

Confirme que `source.reference` corresponde à revisão fixada no manifesto.
Se o lock local estiver desatualizado, resolva a divergência antes de retomar
as emissões. O PHP-Scoper reconstrói os arquivos, mas não invalida o OPcache:
limpe os caches da aplicação e reinicie PHP-FPM e workers.

## Documentos históricos e retorno

O PDF DANFSe é produzido a partir do XML autorizado. A presença de um caminho
de PDF no WebDAV preserva o arquivo já armazenado. Para validar alterações
visuais, gere um novo PDF local de um XML autorizado, sem retransmitir a DPS
ou sobrescrever automaticamente documentos antigos.

Para reverter, restaure uma revisão aprovada do módulo e das dependências,
reconstrua o runtime e reinicie os processos antes de retomar as emissões.
Não reverta migrações nem reemita notas automaticamente.
