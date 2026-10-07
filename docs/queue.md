<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Processamento pós-emissão e filas

O módulo separa a **autorização fiscal** do trabalho que pode ser executado depois da resposta ao usuário.

Depois que a SEFIN autoriza a NFS-e e o recibo local é persistido, o módulo despacha `ProcessNfsePostEmission`. Esse job é responsável por:

1. arquivar o XML autorizado no WebDAV, quando habilitado;
2. gerar o DANFSE a partir do XML autorizado;
3. arquivar o DANFSE no WebDAV;
4. persistir os caminhos dos artefatos no recibo;
5. enviar o e-mail pós-emissão, quando solicitado.

A alteração vale para emissão normal, substituição, reemissão, emissão por grupo fiscal e atualização de recibo.

## Compatibilidade sem worker

O módulo usa a fila padrão do Laravel e **não exige Redis**.

Com:

```env
QUEUE_CONNECTION=sync
```

o job é executado no próprio request, preservando o comportamento de instalações sem infraestrutura de filas.

Isso é compatível, mas não elimina o tempo de geração de DANFSE/WebDAV/e-mail da requisição HTTP.

## Produção com Redis

Para retirar o pós-processamento do request, configure um backend assíncrono:

```env
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

Depois limpe e reconstrua o cache de configuração:

```bash
php artisan optimize:clear
php artisan config:cache
```

Valide:

```bash
php artisan about
php artisan queue:monitor redis:default --max=100
```

`artisan about` deve mostrar `Queue redis`.

## Worker

Um worker precisa compartilhar o mesmo código, banco, configuração e conectividade de rede da aplicação.

Exemplo:

```bash
php artisan queue:work redis \
  --queue=default \
  --sleep=1 \
  --tries=3 \
  --timeout=120 \
  --max-time=3600
```

Em Docker, o worker deve ser um serviço separado. A imagem atual do `akaunting-docker-php` possui uma entrypoint própria que termina em PHP-FPM; portanto, ao reutilizar essa imagem para o worker, sobrescreva a entrypoint e execute explicitamente `queue:work`.

Exemplo de override:

```yaml
services:
  akaunting.queue:
    image: ghcr.io/librecodecoop/akaunting-docker-php:${RUNTIME_VERSION:-3}
    restart: unless-stopped
    user: "${HOST_UID:-1000}:${HOST_GID:-1000}"
    entrypoint:
      - /bin/sh
      - -lc
    command:
      - >
        cd /var/www/html &&
        exec php artisan queue:work redis
        --queue=default
        --sleep=1
        --tries=3
        --timeout=120
        --max-time=3600
    volumes:
      - ./volumes/akaunting:/var/www/html
      - ./overrides/php/zz-production.ini:/usr/local/etc/php/conf.d/zz-production.ini:ro
    networks:
      - default
      - mysql
      - openbao-backend
      - redis
```

Os nomes de redes e volumes dependem da instalação.

## Deploy

Workers Laravel são processos de longa duração. Depois de atualizar o módulo:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

Se o worker usa `--max-time` e política de restart do Docker, ele também será reciclado periodicamente.

## Operação e diagnóstico

Verifique a fila:

```bash
php artisan queue:monitor redis:default --max=100
php artisan queue:failed
```

Verifique o container do worker:

```bash
docker compose ps
docker compose logs --tail=200 akaunting.queue
```

Log vazio com fila em zero é normal: significa que o worker está ocioso.

## Semântica de falha

A autorização fiscal e a persistência do recibo acontecem **antes** do job. Portanto, uma falha posterior de WebDAV, DANFSE ou e-mail não transforma uma NFS-e já autorizada em uma emissão fiscal fracassada.

Isso reduz o risco operacional de o usuário repetir a emissão porque o navegador expirou depois que a SEFIN já havia autorizado o documento.

O job usa apenas IDs persistidos e o XML autorizado como payload; objetos de transporte, certificado e cliente SEFIN não são serializados para a fila.

## Rollback

Para voltar temporariamente ao comportamento síncrono sem remover o worker:

```env
QUEUE_CONNECTION=sync
```

e depois:

```bash
php artisan optimize:clear
php artisan config:cache
```

Nenhuma migração de banco é necessária para alternar entre `sync` e Redis.
