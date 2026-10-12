<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Atualização do runtime nfse-php

A biblioteca `librecodeoop/nfse-php` utiliza PHP-Scoper para isolar
as dependências em `3rdparty/scoped/`. A revisão esperada está declarada
em `3rdparty/composer.json`.

No diretório do módulo Nfse, atualize o runtime e confira a revisão instalada:

```bash
composer thirdparty:build:prod
composer --working-dir=3rdparty show librecodeoop/nfse-php --format=json
```

A referência `source.reference` deve corresponder à revisão declarada
no manifesto. Se o lock local indicar uma revisão anterior, resolva a
divergência antes de retomar as emissões.

No diretório da aplicação Akaunting, limpe os caches:

```bash
php artisan optimize:clear
```

Reinicie o processo PHP e os workers que mantêm código em memória, conforme
a forma de gerenciamento da instalação, para invalidar o OPcache. O processo
de atualização não substitui PDFs já armazenados. Para conferir o layout,
renderize localmente um XML autorizado sem retransmitir a DPS.

O empacotamento do módulo verifica os binários Liberation Sans, a licença OFL,
atribuições, logo e dependências Dompdf no arquivo ZIP produzido, antes da
publicação de uma release. A verificação também roda nos testes de integração:

```bash
bash tools/bin/build-module-archive.sh 0.0.0-ci
php tools/bin/verify-danfse-package.php akaunting-nfse-0.0.0-ci.zip
```

Esse teste comprova a integridade dos recursos distribuídos, não a conformidade
nominal da substituição de Arial/Microsoft Sans Serif prevista na NT008 v1.02.
