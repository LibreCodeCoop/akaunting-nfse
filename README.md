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

### Desenvolvimento local

O módulo não versiona `repositories` no `composer.json`. Para trabalhar localmente com um checkout editável de `nfse-php`, configure o repositório apenas no Composer do seu ambiente de desenvolvimento.

Exemplo neste workspace Docker:

```bash
docker compose exec akaunting.php composer --working-dir=/var/www/html/modules/Nfse \
	config --global repositories.librecodeoop-nfse-php \
	'{"type":"path","url":"/var/www/html/packages/librecodeoop/nfse-php","options":{"symlink":true}}'
```

Esse ajuste fica fora do repositório versionado e serve apenas para desenvolvimento local.

---

## Configuração

### Certificado ICP-Brasil

Faça upload do arquivo `.pfx` em **NFS-e → Configurações → Certificado**.  
A senha é enviada diretamente ao OpenBao — o servidor nunca armazena em texto claro.

### OpenBao / Vault

| Variável de ambiente | Descrição |
|---|---|
| `BAO_ADDR` | Endereço do servidor OpenBao (ex.: `http://openbao:8200`) |
| `BAO_ROLE_ID` | AppRole Role ID |
| `BAO_SECRET_ID` | AppRole Secret ID |
| `BAO_MOUNT` | Mount KV v2 (padrão: `nfse`) |

Para desenvolvimento local, basta um token dev:

```bash
BAO_ADDR=http://localhost:8200
BAO_TOKEN=dev-only-root-token
```

---

## Suporte Comercial

Precisa de SLA, adaptações para outros municípios ou instalação gerenciada?  
Entre em contato: **comercial@librecodecoop.org.br**

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
