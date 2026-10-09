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

O XML autorizado fica persistido em `nfse_receipt_payloads.authorized_xml`, numa relação 1:1 com o recibo. O payload grande fica fora da linha quente de `nfse_receipts`, usada por listagens e relatórios. Assim, uma falha de Redis, worker ou WebDAV depois da autorização não exige uma nova emissão na SEFIN e não perde a fonte necessária para reprocessar os artefatos.

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

# O Akaunting usa a conexão Redis "queue". Estes valores são opcionais
# quando iguais ao Redis padrão:
# REDIS_QUEUE_HOST=redis
# REDIS_QUEUE_PORT=6379
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
        --timeout=60
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

No Akaunting, a conexão Redis de fila usa `retry_after=90` por padrão. O worker e os jobs deste módulo usam timeout de 60 segundos para que um job termine ou falhe antes de ficar elegível para nova tentativa.

O provider de filas do próprio Akaunting adiciona o `company_id` ao payload e restaura a empresa atual no worker antes de processar o job. O módulo utiliza esse mecanismo nativo em vez de serializar ou reimplementar contexto de empresa. O Akaunting também configura `after_commit=true` por padrão para Redis, portanto jobs despachados dentro de uma transação só ficam disponíveis depois do commit.

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

`SendIssuedNfseEmail` usa uma única tentativa automática e registra `post_emission_email_sent_at` depois de uma entrega bem-sucedida, reduzindo o risco de e-mails duplicados. A notificação `NfseIssued` do Akaunting é enfileirável por natureza, mas dentro desse job ela é enviada com `sendNow`/`notifyNow`; assim existe uma única fronteira assíncrona e o marcador de envio representa a execução real da notificação. Se falhar, o job aparece em `queue:failed` e pode ser avaliado antes de um retry manual.

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

## Feedback visual e polling

Quando a fila é assíncrona, a autorização fiscal retorna antes de XML, DANFSE e e-mail terminarem. O módulo persiste o estado das etapas no banco e expõe um endpoint leve de status por fatura.

A interface consulta somente esse estado local. O endpoint de polling não consulta Redis, SEFIN nem WebDAV.

O polling usa backoff limitado:

- a cada 2 segundos nos primeiros 15 segundos;
- a cada 5 segundos até 45 segundos;
- a cada 10 segundos depois disso;
- para automaticamente após 90 segundos ou assim que o processamento conclui/falha.

Os links de XML e DANFSE ficam desabilitados com indicador de processamento enquanto o artefato correspondente ainda não possui path persistido. Quando o job salva o path, o polling libera o link sem recarregar a página.

O modal de resultado disparado por AJAX inicia o scanner explicitamente depois de inserir o HTML; não há `MutationObserver` global varrendo a página.

## WebDAV e throughput

O cliente WebDAV possui timeout de rede de 10 segundos por requisição. Durante uma mesma execução ele também memoriza diretórios já confirmados/criados, evitando repetir `MKCOL` e `HEAD` para o XML e o DANFSE no mesmo caminho. Os paths finais persistidos no recibo tornam o job de artefatos reexecutável sem repetir uploads concluídos.

## Tentativas de emissão e proveniência

O histórico de POST fiscal, respostas ambíguas e rejeições oficiais é persistido
em `nfse_emission_attempts`, separado dos recibos autorizados. Detalhes,
reconciliação somente por leitura e retenção: [segurança fiscal](fiscal-safety.md).
A rota GET `/nfse/invoices/{invoice}/emission-attempts` expõe no máximo as
100 tentativas mais recentes ao administrador da empresa com permissão
`read-sales-invoices`. Não há replay automático do POST por falhas de fila.
