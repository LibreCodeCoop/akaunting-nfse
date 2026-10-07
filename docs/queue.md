<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Processamento pós-emissão e filas

O módulo separa a autorização fiscal da geração de artefatos e do envio de e-mail.

## Fluxo

A emissão mantém no request HTTP apenas o que define o resultado fiscal:

1. monta e envia a DPS;
2. recebe a NFS-e autorizada;
3. persiste o recibo e o XML autorizado;
4. marca a fatura como enviada;
5. despacha o pós-processamento.

O pós-processamento executa:

- arquivamento do XML em WebDAV;
- geração do DANFSE;
- arquivamento do DANFSE em WebDAV;
- envio do e-mail solicitado pelo usuário.

O XML autorizado fica persistido em `nfse_receipts.authorized_xml`. Assim, uma falha de Redis, worker ou WebDAV depois da autorização não exige uma nova emissão na SEFIN e não perde a fonte necessária para reprocessar os artefatos.

## Compatibilidade sem worker assíncrono

O módulo usa a fila nativa do Laravel.

Com:

```env
QUEUE_CONNECTION=sync
```

os jobs são executados no mesmo processo HTTP. Esse modo não exige Redis nem worker e mantém compatibilidade com instalações simples, mas DANFSE/WebDAV/e-mail continuam fazendo parte do tempo da requisição.

Para produção, use uma fila assíncrona.

## Redis

Exemplo:

```env
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

O hostname precisa resolver de dentro do container PHP. Teste:

```bash
php -r '$r = new Redis(); var_dump($r->connect("redis", 6379, 2), $r->ping());'
```

## Worker

O worker precisa usar o mesmo código, `.env`, banco de dados e redes do container PHP.

Exemplo de serviço Docker Compose:

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
        --sleep=1
        --tries=3
        --timeout=120
        --max-time=3600
    volumes:
      - ./volumes/akaunting:/var/www/html
    networks:
      - default
      - mysql
      - redis
```

Adapte as redes e volumes ao ambiente.

A imagem PHP do akaunting-docker possui uma entrypoint própria que termina em `php-fpm`. Por isso o worker deve sobrescrever a `entrypoint`, e não apenas definir `command`.

## Deploy

Depois de alterar `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
```

Reinicie o worker após qualquer deploy de código:

```bash
docker compose restart akaunting.queue
```

Workers Laravel são processos de longa duração e não recarregam classes alteradas automaticamente.

## Validação

Confirme a conexão configurada:

```bash
php artisan about
```

Deve exibir, por exemplo:

```text
Queue  redis
```

Verifique a fila:

```bash
php artisan queue:monitor redis:default --max=100
php artisan queue:failed
```

Com a fila vazia e o worker saudável, é normal não haver saída em `docker compose logs akaunting.queue`.

## Política de retry

`StoreIssuedNfseArtifacts` é idempotente por caminho persistido e aceita até três tentativas. Cada caminho é salvo no recibo logo depois do upload correspondente, portanto uma nova tentativa não repete um artefato que já foi concluído.

`SendIssuedNfseEmail` usa uma única tentativa automática para reduzir risco de e-mails duplicados. Se falhar, o job aparece em `queue:failed` e pode ser avaliado antes de um retry manual.

Os jobs são encadeados: o e-mail só é executado depois do job de artefatos. Isso garante que anexos fiscais solicitados estejam disponíveis antes da montagem da mensagem.

## Falhas depois da autorização

A autorização na SEFIN é a fronteira de consistência mais importante. Depois que o recibo foi persistido, falhas de infraestrutura de fila não podem fazer o controller retornar uma falsa falha de emissão.

O módulo registra erro de dispatch, mas mantém a NFS-e autorizada como emitida. Nunca reemita automaticamente uma nota apenas porque Redis, WebDAV, DANFSE ou e-mail falharam.

Para jobs já enviados à fila:

```bash
php artisan queue:failed
php artisan queue:retry <id>
```

Antes de repetir qualquer emissão fiscal, consulte o recibo local e, quando necessário, reconcilie com a SEFIN/ADN.

## Segurança e dados

O XML autorizado contém dados fiscais e pode conter dados pessoais. O banco de dados do Akaunting e o WebDAV devem seguir a mesma política de acesso, backup, retenção e criptografia aplicada aos demais documentos fiscais.

O XML não é incluído no payload do job. A fila transporta apenas identificadores do recibo/fatura e os parâmetros necessários para o e-mail.
