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

O **akaunting-nfse** integra o seu [Akaunting](https://akaunting.com/) (self-hosted) com o gateway SEFIN Nacional, permitindo que sua empresa emita NFS-e **sem sair do sistema contábil**.

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

| Variável de ambiente | Descrição |
|---|---|
| `VAULT_ADDR` | Endereço do servidor OpenBao/Vault (ex.: `http://openbao:8200`) |
| `VAULT_ROLE_ID` | AppRole Role ID |
| `VAULT_SECRET_ID` | AppRole Secret ID |
| `VAULT_MOUNT` | Mount KV v2 (padrão: `nfse`) |
| `VAULT_TOKEN` | Token para desenvolvimento/CI |

Para desenvolvimento local, basta um token dev:

```bash
VAULT_ADDR=http://localhost:8200
VAULT_TOKEN=dev-only-root-token
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
