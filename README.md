<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# NFS-e Nacional no Akaunting — akaunting-nfse

**Emita e acompanhe Notas Fiscais de Serviço Eletrônicas (NFS-e) pelo padrão nacional sem sair do Akaunting.** O módulo livre **akaunting-nfse** conecta o gerenciamento financeiro e as faturas do Akaunting aos fluxos nacionais de emissão, consulta e cancelamento de NFS-e.

[![CI](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml/badge.svg)](https://github.com/LibreCodeCoop/akaunting-nfse/actions/workflows/phpunit.yml)
[![Versão Packagist](https://img.shields.io/packagist/v/librecodeoop/akaunting-nfse)](https://packagist.org/packages/librecodeoop/akaunting-nfse)

> **English:** Open-source Brazilian National NFS-e integration for Akaunting: issue and manage electronic service invoices, generate DANFSe PDFs and use fiscal data within your invoicing workflow.

## Por que usar o módulo NFS-e para Akaunting?

Sua equipe já organiza clientes, serviços e faturas no Akaunting. O módulo conecta essas informações à **Nota Fiscal de Serviço Eletrônica Nacional**, reduzindo a necessidade de alternar entre sistemas para acompanhar a documentação fiscal.

Ele permite integrar o trabalho financeiro ao acompanhamento de NFS-e, com rastreabilidade dos documentos autorizados e regras de segurança que evitam confundir uma fatura comercial com uma nota fiscal emitida.

## Principais recursos

- **Emissão de NFS-e Nacional** a partir de faturas e serviços cadastrados no Akaunting, com validações antes de transmitir a DPS.
- **Consulta e cancelamento** e acompanhamento da situação dos documentos fiscais.
- **DANFSe em PDF** gerada a partir do XML autorizado, com possibilidade de arquivamento de artefatos fiscais.
- **Perfis fiscais por serviço e dados fiscais do tomador**, mantendo o cadastro comercial separado da identificação tributária necessária.
- **Acompanhamento por fatura e por nota fiscal**, sem confundir situação financeira com situação fiscal.
- **Integrações com SEFIN e ADN**, incluindo consultas oficiais e funcionalidades de reconciliação.
- **Gestão de segredos** com OpenBao ou HashiCorp Vault para cenários que utilizem certificados digitais.

As informações tributárias dependem dos cadastros e da operação concreta. O módulo não substitui a análise fiscal nem promete ativar automaticamente novos leiautes publicados.

## Funciona com seu Akaunting

O projeto é um módulo para o [Akaunting](https://akaunting.com/) e utiliza a biblioteca [nfse-php](https://github.com/LibreCodeCoop/nfse-php) para integração ao protocolo nacional. A biblioteca é independente; este módulo acrescenta os fluxos e as telas do Akaunting.

Para requisitos de versão, instalação e atualização do módulo, consulte a [documentação operacional](docs/operacao-e-configuracao.md). Para atualização da biblioteca isolada, veja [Atualização do runtime](docs/nfse-php-runtime-upgrade.md).

## Documentação

- [Configuração e operação da NFS-e no Akaunting](docs/operacao-e-configuracao.md)
- [Emissão e requisitos fiscais](docs/emissao-vigente.md)
- [Garantias de segurança fiscal](docs/fiscal-safety.md)
- [Processamento assíncrono e filas](docs/queue.md)
- [Dados fiscais adicionais do cliente](docs/contact-fiscal-profile.md)
- [Integração com o Core do Akaunting](docs/akaunting-core-integration.md)

## Suporte e implantação profissional

A [LibreCode](https://librecodecoop.org.br) desenvolve soluções livres, presta consultoria, suporte, integração e manutenção. Para implantar NFS-e no Akaunting, revisar procedimentos fiscais, integrar outros sistemas ou contratar atendimento especializado:

**[comercial@librecodecoop.org.br](mailto:comercial@librecodecoop.org.br)**

A comunidade também pode colaborar: [issues](https://github.com/LibreCodeCoop/akaunting-nfse/issues) e [guia de contribuição](CONTRIBUTING.md).
