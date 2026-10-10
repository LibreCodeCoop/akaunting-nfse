<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contribuir com akaunting-nfse

Este projeto estende o Akaunting para NFS-e Nacional. Antes de contribuir,
confirme a versão de Akaunting utilizada, a distinção entre campos comerciais
e fiscais e os contratos da biblioteca `nfse-php`.

- Abra issues com reprodução usando somente dados sintéticos; nunca inclua XML, PDF, dados pessoais ou credenciais reais de clientes.
- Para alterações em emissão, cancelamento e persistência, inclua testes unitários e de integração que não façam transmissões fiscais reais.
- Execute verificações relevantes de PHPUnit, Psalm, PHP-CS-Fixer, migrações e testes de interface. Não oculte falhas de CI.
- Evite modificar o Core Akaunting. Prefira contratos de extensão e mantenha isolamento multiempresa e segurança fiscal.
- Se `3rdparty/composer.json` mudar, valide o runtime reconstruído por `composer thirdparty:build:prod` e indique a necessidade de atualização aos mantenedores.
- Descreva motivação, critérios de aceitação e evidências no PR; mantenha a documentação apenas com comportamentos e procedimentos permanentes.
- Use Conventional Commits, DCO (`git commit -s`) e assinatura criptográfica configurada quando disponível.

O texto da licença aplicável ao código está em `LICENSE`; outros recursos podem possuir condições próprias indicadas em `LICENSES/` e nos arquivos SPDX.
