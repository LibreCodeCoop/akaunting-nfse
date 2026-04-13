<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# akaunting-nfse

> Módulo Akaunting para emissão, consulta e cancelamento de **Nota Fiscal de Serviço Eletrônica (NFS-e)** via SEFIN Nacional (ABRASF 2.04 / SEFIN 1.0).

[![Latest Version](https://img.shields.io/packagist/v/librecodeoop/akaunting-nfse?style=flat-square)](https://packagist.org/packages/librecodeoop/akaunting-nfse)
[![PHP Version](https://img.shields.io/packagist/php-v/librecodeoop/akaunting-nfse?style=flat-square)](https://packagist.org/packages/librecodeoop/akaunting-nfse)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg?style=flat-square)](https://www.gnu.org/licenses/agpl-3.0)
[![CI](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml/badge.svg)](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml)

---

## O que é?

O **akaunting-nfse** integra o seu [Akaunting](https://github.com/LibreCodeCoop/akaunting-docker) (self-hosted) com o gateway SEFIN Nacional, permitindo que sua empresa emita NFS-e **sem sair do sistema contábil**.

Diferenciais:
- **Credenciais isoladas** — a senha do certificado ICP-Brasil **nunca** vai para o banco de dados; ela é armazenada em [OpenBao](https://openbao.org/) / HashiCorp Vault KV v2
- **Interface nativa Akaunting** — menu, configurações e agenda integrados ao painel
- **Emissão em lote** — agende emissões automáticas por tipo de cobrança
- **Auditoria completa** — todo XML enviado e recebido é arquivado em WebDAV configurável

---

## Requisitos

| Dependência | Versão |
|---|---|
| Akaunting | ^4.0 |
| PHP | ^8.2 |
| ext-openssl | * |
| OpenBao / Vault | ^1.x (KV v2) |

---

## Instalação

1. Baixe o módulo na loja Akaunting (em breve) ou instale via Composer:

```bash
composer require librecodeoop/akaunting-nfse
```

2. Habilite o módulo em **Configurações → Módulos → NFS-e**
3. Configure o certificado e as credenciais OpenBao em **NFS-e → Configurações**

---

## Configuração

### Certificado ICP-Brasil

Faça upload do arquivo `.pfx` em **NFS-e → Configurações → Certificado**.
A senha é enviada diretamente ao OpenBao — o servidor nunca armazena em texto claro.

### OpenBao / Vault

O módulo armazena as credenciais nos campos da aba **NFS-e → Configurações**:

| Campo | Descrição |
|---|---|
| Endereço OpenBao / Vault | URL do servidor (ex.: `http://openbao:8200`) |
| Mount KV v2 | Path do mount (ex.: `/nfse`) |
| Token | Token estático — use apenas em desenvolvimento ou CI |
| AppRole Role ID | Role ID gerado pelo AppRole (produção) |
| AppRole Secret ID | Secret ID gerado pelo AppRole (produção) |

### Prontidão operacional antes de emitir

Antes de emitir NFS-e, valide a tela **NFS-e -> Configuracoes -> Prontidao operacional**.

Ela precisa indicar **Sim** para todos os itens, incluindo:

- CNPJ do prestador salvo
- Municipio IBGE configurado
- Item da lista LC 116 configurado
- Endereco OpenBao configurado
- Mount OpenBao configurado
- Certificado local disponivel
- Segredo do certificado disponivel no Vault/OpenBao

Se o ultimo item estiver pendente, a emissao sera bloqueada para evitar falha em tempo de envio.

#### Desenvolvimento

O ambiente de desenvolvimento utiliza o [akaunting-docker](https://github.com/LibreCodeCoop/akaunting-docker), que já inclui o OpenBao no `docker-compose.override.yml`.

Ao subir o ambiente pela primeira vez, o serviço `openbao-init` cria automaticamente o mount KV v2 e habilita o AppRole. Você pode verificar:

```bash
docker compose exec -e BAO_ADDR=http://127.0.0.1:8200 -e BAO_TOKEN=dev-only-root-token \
  openbao bao secrets list
```

O módulo já sabe o endereço do OpenBao via variável de ambiente `OPENBAO_ADDR=http://openbao:8200`
(injetada no container `akaunting.php` pelo `docker-compose.yml`), então não é necessário configurar
manualmente o endereço na tela de configurações para o ambiente docker-compose local.

Configure o módulo com:
- **Endereço**: `http://openbao:8200` (padrão já preenchido)
- **Token**: `dev-only-root-token` (padrão do modo dev)
- **Mount**: `/nfse` (padrão já preenchido)

#### Produção (AppRole)

AppRole é o método recomendado para produção, pois não expõe um token de longa duração.

```bash
# 1. Habilite o método AppRole
bao auth enable approle

# 2. Crie uma policy restrita ao path do módulo
bao policy write nfse - <<EOF
path "nfse/*" {
  capabilities = ["create", "read", "update", "delete", "list"]
}
EOF

# 3. Crie o role vinculado à policy
bao write auth/approle/role/nfse \
  token_policies="nfse" \
  token_ttl=1h \
  token_max_ttl=4h

# 4. Obtenha o Role ID (preencha em "AppRole Role ID" no módulo)
bao read auth/approle/role/nfse/role-id

# 5. Gere um Secret ID (preencha em "AppRole Secret ID" no módulo)
bao write -f auth/approle/role/nfse/secret-id

# 6. Habilite o mount KV v2
bao secrets enable -path=nfse kv-v2
```

---

## Suporte Comercial

Precisa de SLA, adaptações para outros municípios ou instalação gerenciada?
Entre em contato: **comercial@librecodecoop.org.br**

---

## Testes E2E (Playwright)

O módulo inclui uma suíte E2E opcional com Playwright para validar o fluxo visível no frontend (login + tela de configurações NFS-e).

1. Defina variáveis de ambiente (veja `.env.e2e.example`):

```bash
export NFSE_E2E_BASE_URL="http://localhost:8080"
export NFSE_E2E_EMAIL="admin@local"
export NFSE_E2E_PASSWORD="sua-senha"
```

2. Instale dependências e rode os testes:

```bash
npm install
npx playwright install chromium
npm run test:e2e
```

No GitHub Actions, há workflow manual em `.github/workflows/playwright-e2e.yml` com `workflow_dispatch`, usando os secrets `NFSE_E2E_EMAIL` e `NFSE_E2E_PASSWORD`.

---

## Testes de API/Fluxo com Behat

Além dos E2E com browser, o módulo agora possui uma suíte Behat para validar contratos HTTP dos endpoints do NFS-e com menor custo de execução.

1. Defina variáveis de ambiente para o ambiente Akaunting alvo (veja `.env.behat.example`):

```bash
export NFSE_BEHAT_BASE_URL="http://localhost:8082"
export NFSE_BEHAT_EMAIL="admin@akaunting.test"
export NFSE_BEHAT_PASSWORD="sua-senha"
export NFSE_BEHAT_COMPANY_ID="1"
```

2. Rode os cenários:

```bash
composer test:behat:guest   # sem credenciais, cobre guardas de autenticação
composer test:behat:auth    # requer credenciais, cobre endpoints autenticados
```

### Estratégia de segurança (CI sem PFX/CNPJ reais)

- Use CNPJ de fixture em sandbox (`12345678901234`) apenas para validar fluxo técnico.
- Use fixture `.p12` inválida/sintética para validar endpoint de upload sem credenciais fiscais reais.
- Não execute emissão real em CI: os cenários cobrem roteamento/autorização/validação e contratos de resposta.
- Para ambiente controlado de homologação com credenciais reais, use workflow manual e segredos do repositório (nunca em código/versionamento).

---

## Contribuindo

PRs são bem-vindos. Leia o [guia de contribuição](CONTRIBUTING.md) antes de abrir um PR.

Commits devem seguir [Conventional Commits](https://www.conventionalcommits.org/) e ser assinados com `git commit -s`.

---

## Dê uma estrela!

Se este módulo simplifica a sua operação fiscal, por favor ⭐ o repositório.
Isso ajuda outros desenvolvedores a encontrar o projeto e encoraja a equipe a continuar melhorando.

---

## Licença

GNU Affero General Public License v3.0 ou superior — veja [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt).
&copy; 2026 LibreCode Coop e colaboradores.
