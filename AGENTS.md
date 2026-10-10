<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AGENTS.md — akaunting-nfse

Regras para agentes de IA e contribuições neste módulo do **Akaunting** (escreva sempre Akaunting; não substitua o nome por "Accounting"). Aplicam-se a todo o repositório.

## Escopo e contratos

- Este é um módulo do Akaunting, não uma biblioteca fiscal PHP autônoma. A API e o protocolo fiscal genérico pertencem a [nfse-php](https://github.com/LibreCodeCoop/nfse-php). Evite duplicar serialização, assinatura e regras de protocolo aqui; use a biblioteca e os contratos públicos.
- Respeite extensões/eventos oficiais do Akaunting sem alterar arquivos do Core. Minimize overrides de views nativas. Confira a versão de Core realmente integrada antes de propor hooks ou tabelas novos.
- `document_items.description` pertence à linha da fatura e nunca deve compor automaticamente a discriminação da NFS-e. O nome comercial do item pode identificar o serviço por padrão; preserve a descrição informada na emissão e as configurações gerais. `documents.notes` contém observações da fatura. Mantenha a composição por grupo fiscal isolada e testável.
- A inscrição municipal e o nome fiscal do tomador devem vir de cadastros autorizados e permanecer isolados por empresa. Nunca invente dados ausentes, atravesse tenant boundaries ou permita que campos de um cliente apareçam em outro.
- Trate NFS-e autorizada, fatura financeira, artefato PDF e estados de recuperação como entidades distintas. Resposta fiscal ambígua exige reconciliação, não retransmissão cega; não gere outra NFS-e para testar layout.

## Composer, PHP-Scoper e implantação

- O módulo fixa `librecodeoop/nfse-php` em **`3rdparty/composer.json`**. Ao mudar esse pin, verifique contrato/API, testes de empacotamento e a presença de recursos como o PNG da DANFSe em `3rdparty/scoped/`.
- O fluxo de atualização do runtime é **`composer thirdparty:build:prod`**, executado no diretório do módulo; o script já chama instalação do `3rdparty`, preparação de ferramentas e PHP-Scoper. Não recomende `composer update` adicional por reflexo. Observe que `3rdparty/composer.lock` pode existir localmente e não estar versionado: compare `source.reference` ao pin com `composer --working-dir=3rdparty show librecodeoop/nfse-php --format=json` e resolva divergência comprovada antes de liberar o sistema.
- Para consumidores implantados, descreva operações **independentes de Docker e caminhos locais**. Após atualização, indique limpar cache do Akaunting (`php artisan optimize:clear`) e reiniciar os processos PHP e filas que utilizam OPcache, conforme a instalação. Não presuma que Git deve rodar dentro de contêiner nem que um merge alterou o ambiente.
- Ao concluir PR que muda runtime/manifesto, inclua na resposta ao mantenedor a atualização mínima exigida e o método para verificar a revisão carregada. Não misture atualização do código no host com suposições sobre gerenciador de serviços.
- PDFs arquivados em WebDAV não são recriados automaticamente. A validação de renderização utiliza XML autorizado de fixture, sem nova emissão e sem sobrescrever documentos históricos.

## Segurança, privacidade e licenciamento

- Testes, READMEs, docs, logs e PRs devem usar apenas entidades, inscrições, valores, telefones, endereços e nomes **fictícios**, ou documentos oficialmente públicos cuja licença permita a reprodução. Nunca copie dados reais de clientes/colaboradores, centro de custo, XML/PDF, credenciais ou detalhes internos de atendimento para fixtures.
- Não inclua certificados privados/PFX reais, segredos, tokens, Authorization ou PII nos logs e screenshots da CI. Teste mascaramento e privilégios por empresa; não esconda falha fiscal/autorização para passar teste.
- Preserve `LICENSE` raiz, `LICENSES/`, `REUSE.toml` e marcações SPDX. Não relicense ativos de terceiros. Mudanças em dados fiscais, autenticação, persistência ou tenancy exigem testes de segurança regressivos.

## Código, documentação e revisão

- Extraia regras de negócio complexas para classes com métodos pequenos e testes unitários puros. Evite `eval()` em testes: mantenha stubs em `tests/`, organizados por classe/namespace e separados dos testes de integração. Use doubles só quando indispensáveis.
- Testes de UI, migrações, emissão, APIs e PHP-CS/Psalm precisam validar o comportamento, não a mera presença de trechos de código. Não aumente timeouts, afrouxe asserts nem pule checks sem uma causa comprovada.
- `README.md` deve explicar benefícios, público, uso, documentação e suporte comercial. Detalhes operacionais ficam em `docs/`, sem narrativas de PR, incidentes transitórios, diretórios internos, exemplos Docker particulares ou dados de clientes. Atualize docs quando o contrato **duradouro** mudar; reporte andamento no PR.
- Use Conventional Commits, DCO (`Signed-off-by`) e preferencialmente o serviço de commits criptograficamente assinados configurado. Não afirme merge, CI verde ou atualização em produção sem comprovação.
