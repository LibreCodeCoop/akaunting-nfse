<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# akaunting-nfse

> Módulo Akaunting para emissão, consulta, cancelamento e diagnóstico de **Nota Fiscal de Serviço Eletrônica (NFS-e)** no padrão nacional, com integração SEFIN/ADN.

[![Latest Version](https://img.shields.io/packagist/v/librecodeoop/akaunting-nfse?style=flat-square)](https://packagist.org/packages/librecodeoop/akaunting-nfse)
[![PHP Version](https://img.shields.io/packagist/php-v/librecodeoop/akaunting-nfse?style=flat-square)](https://packagist.org/packages/librecodeoop/akaunting-nfse)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg?style=flat-square)](https://www.gnu.org/licenses/agpl-3.0)
[![CI](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml/badge.svg)](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml)

---

## O que é?

O **akaunting-nfse** integra o seu [Akaunting](https://github.com/LibreCodeCoop/akaunting-docker) (self-hosted) com o gateway SEFIN Nacional, permitindo que sua empresa emita NFS-e **sem sair do sistema contábil**.

Diferenciais:
- **Credenciais isoladas** — a senha do certificado ICP-Brasil **nunca** vai para o banco de dados; ela é armazenada em [OpenBao](https://openbao.org/) / HashiCorp Vault KV v2
- **Interface nativa Akaunting** — emissão, consulta, cancelamento, reemissão, configurações e diagnóstico operacional integrados ao painel
- **NFS-e Nacional atual** — suporte ao layout DPS/NFS-e v1.01, CNPJ alfanumérico, IBS/CBS, cenários especiais de ISSQN e tomador estrangeiro
- **ADN** — consulta de documentos por NSU, eventos oficiais, reconciliação somente leitura com recibos locais e diagnóstico de integridade XMLDSig
- **DANFSe v2.0** — geração local alinhada à NT 008, incluindo exibição de IBS/CBS quando presente no XML autorizado
- **Auditoria completa** — XMLs e artefatos de emissão podem ser arquivados em WebDAV configurável

---

## Requisitos

| Dependência | Versão |
|---|---|
| Akaunting | ^4.0 |
| PHP | ^8.2 |
| ext-openssl | * |
| Secret store | OpenBao or HashiCorp Vault with KV v2 |

---

## Instalação

1. Baixe o módulo na loja Akaunting (em breve) ou instale via Composer:

```bash
composer require librecodeoop/akaunting-nfse
```

2. Habilite o módulo em **Configurações → Módulos → NFS-e**
3. Configure o certificado e o secret store (OpenBao/Vault) em **NFS-e → Configurações**

---

## Configuração

### Certificado ICP-Brasil

Faça upload do arquivo `.pfx` em **NFS-e → Configurações → Certificado**.
A senha é enviada diretamente ao OpenBao — o servidor nunca armazena em texto claro.

### IBS/CBS (Reforma Tributaria)

Para operacoes sujeitas as regras RTC, configure na aba **Tributacao**:

- habilitacao do grupo IBS/CBS;
- `cIndOp` (6 digitos), conforme a tabela oficial de indicador da operacao;
- `indDest` (0 ou 1);
- `CST` (3 digitos);
- `cClassTrib` (6 digitos);
- `indFinal` (opcional, 0 ou 1).

O modulo nao infere esses codigos automaticamente. O enquadramento fiscal deve ser
definido de acordo com a operacao e as tabelas oficiais. Quando habilitado, o
plugin envia `finNFSe=0` (NFS-e regular), unico valor atualmente admitido pelo
schema v1.01.

### Diagnóstico oficial e ADN

O módulo inclui recursos de diagnóstico somente leitura para comparar a configuração local e os documentos emitidos com os dados oficiais:

- consulta de parametrização municipal no ADN;
- consulta de eventos oficiais por chave de acesso;
- distribuição de documentos por NSU;
- reconciliação de documentos ADN com recibos locais pela chave de acesso;
- verificação de integridade XMLDSig dos XMLs recebidos.

Essas funções não alteram automaticamente o enquadramento fiscal nem importam/cancelam documentos locais.

### Tomador estrangeiro e cenários especiais de ISSQN

A emissão suporta, quando aplicável:

- tomador estrangeiro com NIF, país e endereço exterior;
- imunidade;
- exportação de serviços com país de resultado;
- exigibilidade suspensa com tipo e número de processo;
- retenção de ISSQN tratada separadamente da tributação da operação.

### Processamento pós-emissão e filas

A autorização fiscal é persistida antes da geração de DANFSE, arquivamento WebDAV e envio de e-mail.

O módulo funciona nos dois modos:

- `QUEUE_CONNECTION=sync`: compatibilidade sem worker; o pós-processamento continua no request.
- backend assíncrono, como Redis: o request retorna após a persistência fiscal e um worker conclui artefatos/e-mail.

Para produção, configuração do worker, Docker, deploy, diagnóstico e rollback, veja [docs/queue.md](docs/queue.md).

### OpenBao / Vault

O módulo consome um OpenBao/Vault já existente. Ele não instala nem inicializa
esse serviço como parte do Akaunting.

Configure os campos da aba **NFS-e → Configurações**:

| Campo | Descrição |
|---|---|
| Endereço OpenBao / Vault | URL do servidor (ex.: `http://openbao:8200`) |
| Mount KV v2 | Path do mount (ex.: `/nfse`) |
| Token | Token estático — use apenas em desenvolvimento ou CI |
| AppRole Role ID | Role ID gerado pelo AppRole (produção) |
| AppRole Secret ID | Secret ID gerado pelo AppRole (produção) |

### Prontidão operacional antes de emitir

Antes de emitir NFS-e, valide a tela **NFS-e -> Configuracoes -> Prontidao operacional**.

Ela precisa indicar **Sim** para todos os itens de configuracao global, incluindo:

- CNPJ do prestador salvo
- Municipio IBGE configurado
- Endereco OpenBao configurado
- Mount OpenBao configurado
- Certificado local disponivel
- Segredo do certificado disponivel no Vault/OpenBao

A classificacao fiscal do servico, incluindo o item da lista LC 116, pertence ao perfil fiscal de cada item e e validada no contexto da fatura antes da emissao.

Se o ultimo item global estiver pendente, a emissao sera bloqueada para evitar falha em tempo de envio.

### Mapeamento de tributos federais por nome

Na emissao da NFS-e, os tributos federais (PIS/COFINS/IRRF/CSLL) sao derivados dos impostos dos itens da fatura.

Se os tributos federais exigidos para o perfil configurado nao estiverem presentes nos itens, o botao de emissao nao e exibido na listagem pendente e a emissao/reemissao e bloqueada no backend.

Como o cadastro padrao de impostos do Akaunting (`taxes`) nao possui um campo estruturado para codigo fiscal (mantem principalmente `name`, `rate` e `type`), a classificacao e feita pelo texto do nome do imposto.

Termos reconhecidos (com normalizacao de acentos e caixa):

| Tributo | Termos/variantes reconhecidos |
|---|---|
| PIS | `pis`, `pasep`, `programa de integracao social` |
| COFINS | `cofins`, `financiamento da seguridade social` |
| IRRF | `irrf`, `imposto de renda retido na fonte`, `renda retida na fonte` |
| CSLL | `csll`, `contribuicao social sobre o lucro liquido` |
| CP (previdenciaria) | `inss`, `contribuicao previdenciaria`, `previdencia social` |

Hints de codigo textual aceitos no nome do imposto:

| Padrao | Exemplo |
|---|---|
| Prefixo `cod:` | `cod:pis` |
| Prefixo `codigo` | `codigo irrf` |
| Prefixo `cst:` | `cst:cofins` |
| Marcador em colchetes | `[csll]` |

Recomendacao para reduzir ambiguidades:

- Inclua sempre o identificador explicito no nome do imposto (ex.: `IRRF - Servicos` ou `cod:irrf - Servicos PJ`).
- Evite depender apenas de codigos numericos no nome (ex.: apenas `0561`), pois esses codigos variam por contexto fiscal e nao sao suficientes, sozinhos, para classificacao automatica segura no modulo.

#### Desenvolvimento

O [akaunting-docker](https://github.com/LibreCodeCoop/akaunting-docker) sobe o
Akaunting sem OpenBao por padrão. Para desenvolvimento do NFS-e, habilite
explicitamente o setup opcional de OpenBao documentado naquele repositório.

Esse setup usa armazenamento persistente e Static Key Auto Unseal. Depois da
inicialização única, reiniciar os containers ou a VPS não exige informar shares
de unseal novamente.

O módulo não assume um hostname padrão para o secret store. Configure o endereço
na interface ou forneça `VAULT_ADDR`/`OPENBAO_ADDR` no ambiente. O mount
continua usando `/nfse` como padrão.

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
