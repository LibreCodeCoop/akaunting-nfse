<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Atualizar o runtime nfse-php sem ativar a NT009 em producao

O modulo usa `librecodeoop/nfse-php` isolado pelo PHP-Scoper em
`3rdparty/scoped/`. A revisao efetivamente usada fica fixada em
`3rdparty/composer.json`; nao depende do `composer.json` do Akaunting.

Revisao avaliada: `2f9324a2eed77625ba7120eb1a92ec75f15207e1` (merge
do nfse-php PR #104). A versao anterior era
`f179d7f723bec80f5b19a25dfbc385a612c2447f`.

A versao atual acrescenta o catalogo versionado do Anexo VII v1.03.00 e
um modelo de **pre-visualizacao nao emissora** da NT009 v1.01. O contrato
`NfseClient::emit(DpsData)`, o serializador normal
`XmlBuilder::buildDps(DpsData)` e o XSD de producao permanecem os mesmos.
Nao configure o modulo para emitir a estrutura de preview: falta comprovar
o esquema oficialmente ativo em cada ambiente, a data de ativacao e a
aceitacao real pelo gateway.

## Atualizacao do modulo e das dependencias

Requer PHP >= 8.2, Composer e as extensoes PHP indicadas no nfse-php.
Faça backup do banco de dados e dos arquivos persistentes do Akaunting.
Interrompa os workers de fila e coloque a aplicacao em manutencao durante
a janela de troca de codigo, seguindo o procedimento de operacao local.

**Depois do merge do PR deste modulo**, no checkout existente:

```bash
cd /var/www/html/modules/Nfse
git fetch origin main
git pull --ff-only origin main

# Atualize tambem o lock local (nao versionado) para que 'composer install'
# nao continue resolvendo a antiga revisao de nfse-php.
composer --working-dir=3rdparty update librecodeoop/nfse-php --with-all-dependencies --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
composer runtime-tools:install
composer thirdparty:scope

# Confira a revisao instalada pelo Composer (source.reference).
composer --working-dir=3rdparty show librecodeoop/nfse-php --format=json
```

Verifique no JSON acima que `source.reference` corresponde ao commit
`2f9324a2eed77625ba7120eb1a92ec75f15207e1`. Caso nao corresponda,
**nao retome a emissao**: investigue o lock local antes de prosseguir.
Apenas atualizar o arquivo `3rdparty/composer.json` nao recompila o
runtime em `3rdparty/scoped/`.

A partir da raiz do Akaunting, com o mesmo ambiente PHP e variaveis da
aplicacao:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Reinicie os processos PHP-FPM/filas conforme o orquestrador e verifique
em homologacao a consulta, emissao com fixture controlada, XML assinado,
retorno SEFIN, recuperacao de tentativa ambigua e downloads de artefatos.
So remova a manutencao depois da verificacao. Nao repita emissoes de
resultado ambiguo; execute antes a conciliacao oficial por identificador DPS.

## Reversao

Guarde o SHA anterior do modulo e um backup dos arquivos de configuracao
e do banco. Para reverter **somente o runtime**, fixe novamente
`dev-main#f179d7f723bec80f5b19a25dfbc385a612c2447f` no manifest
local, atualize a dependencia (comando `composer --working-dir=3rdparty
update librecodeoop/nfse-php --with-all-dependencies ...`) e refaca
`composer thirdparty:scope`. Prefira reverter pelo commit do modulo
validado em vez de editar codigo de producao. Nao execute rollback de
migracoes sem verificar compatibilidade de dados e sem backup.

Consulte `docs/queue.md` para a politica local de workers e retries.
