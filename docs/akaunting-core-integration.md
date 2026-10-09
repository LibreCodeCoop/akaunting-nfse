<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Compatibilidade com o Akaunting

O módulo integra a emissão NFS-e ao fluxo de documentos e itens do
Akaunting sem substituir as funções comerciais nativas.

## Faturas

A ação nativa **Enviar** continua responsável pela entrega comercial.
As ações fiscais manuais ficam no painel NFS-e da fatura. Quando a
política `emit_on_send` está habilitada, o evento nativo
`DocumentSending` é o ponto de entrada para o preflight e a emissão.
Falhas fiscais devem interromper o envio comercial quando essa
política exige emissão prévia; reenvios não devem criar uma nova DPS
para documento já autorizado.

## Itens

A personalização dos formulários de criação e edição utiliza atualmente
os adaptadores:

- `Resources/overrides/common/items/create.blade.php`
- `Resources/overrides/common/items/edit.blade.php`

São pontos sensíveis a mudanças do template do Akaunting. Se a versão
suportada passar a oferecer um ponto de extensão oficial para esses
campos, deve-se preferi-lo aos overrides.

Persistência e validação de perfil fiscal devem ocorrer no mesmo
limite transacional dos dados comerciais do item para evitar sucesso
parcial. Operações que não enviam campos fiscais não devem sobrescrever
um perfil preexistente. Arquivos e efeitos externos não são revertidos
por uma transação de banco de dados; jobs assíncronos exigem validação
do ciclo de vida ao atualizar o Akaunting.

## Verificação antes de atualizar o núcleo

1. Compare os adaptadores de item com os templates da versão de destino.
2. Reavalie eventos e transações das ações nativas de itens/documentos.
3. Verifique permissões, isolamento entre empresas e funcionamento da fila.
4. Execute testes de integração com Akaunting e os cenários determinísticos
   de navegador antes de aprovar o upgrade.

Registre divergências específicas da versão nas issues e nos PRs.
Este guia descreve o contrato mantido, não o histórico de auditorias.
