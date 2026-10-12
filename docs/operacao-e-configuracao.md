<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Configuração e operação do módulo NFS-e

O módulo `akaunting-nfse` integra o [Akaunting](https://akaunting.com/) aos
serviços do Sistema Nacional NFS-e. Requer versão compatível do Akaunting,
PHP e extensões declaradas nos manifestos e acesso aos serviços nacionais
aplicáveis. O `module.json` atual não declara uma faixa de versões do Core;
confirme a compatibilidade da versão instalada com os testes e releases do módulo.

## Habilitação

Instale e habilite o módulo por um dos mecanismos suportados pelo seu ambiente
Akaunting. Nas configurações NFS-e, informe os dados do prestador, as opções
de operação, certificado digital e o secret store. Os caminhos e meios de
instalação dependem da hospedagem e não fazem parte do contrato do módulo.

## Certificados e segredos

Os recursos de proteção de certificado utilizam OpenBao ou HashiCorp Vault,
com KV v2. Configure URL, mount e credenciais pela interface prevista.
Prefira credenciais com escopo mínimo e ciclo de vida gerenciável, como AppRole
quando suportado. Não registre nem publique senha PFX, token, Role/Secret ID
ou chave privada. Não envie esses valores em anexos de issues ou logs.

### Configuração global e verificação

A infraestrutura OpenBao/Vault possui exatamente um registro administrativo global
criptografado no banco `nfse_vault_infrastructure`, sem `company_id`. O
registro administrativo, quando existente, prevalece integralmente sobre
variáveis de ambiente. Se não existir, a resolução segue `VAULT_*` e
depois `OPENBAO_*`, com mount padrão `/nfse`. Não se reutilizam configurações
legadas `nfse.bao_*`; é necessário reconfigurar a infraestrutura.

Alterações administrativas exigem `update-nfse-settings`; diagnóstico manual
exige `read-nfse-settings`. Os segredos são criptografados no banco usando
a chave da aplicação Akaunting e não retornam em respostas. Faça backup seguro
da chave de aplicação: sua perda impede descriptografar credenciais.

O diagnóstico sob demanda não armazena nem modifica certificados. Verifica
saúde, autenticação, KV v2 e capacidade de leitura/criação/atualização
em um caminho sintético `pfx/__nfse_capability_probe__`. O resultado
`operational` descreve apenas o instante da verificação e não garante
operações futuras. A consulta não é requisito para listar documentos/perfis
pré-existentes. O endpoint exige HTTPS; conexões inseguras não são verificadas.
O teste não envia NFS-e nem utiliza SEFIN/ADN.

## Perfis NFS-e por empresa

O Akaunting continua sendo o cadastro mestre de empresas. Um perfil NFS-e
pertence a um `company_id` existente, habilitado e atribuído ao usuário.
Cada empresa pode ter apenas um perfil e a verificação ocorre também por
índice único no banco. O mesmo CNPJ pode existir em mais de uma empresa
sem compartilhar certificados.

A infraestrutura OpenBao/Vault é global, mas a senha do PFX e a referência
do certificado são isoladas pelo caminho imutável
`pfx/companies/{company_id}/{reference}`. O arquivo PFX permanece em
armazenamento privado para transporte mTLS. O cadastro requer certificado
válido, senha correta, identidade fiscal identificável e igualdade com
`company.tax_number` do Akaunting. As permissões no **caminho real**
do KV v2 devem ser verificadas; uma probe genérica não basta.

### Contrato administrativo de backend

A interface de empresas (#420) deverá utilizar estas rotas nomeadas.
A consulta deve permanecer possível mesmo durante indisponibilidade
temporária do Vault:

- `nfse.settings.company-profiles.index`: GET; retorna `profiles`
  (metadados sem credenciais) e `eligible_companies` (somente empresas
  acessíveis ainda sem perfil); permissão `read-nfse-settings`.
- `nfse.settings.company-profiles.store`: POST com `company_id`,
  `pfx_file`, `pfx_password` e `fiscal_parameters` opcionais;
  permissão `update-nfse-settings`.
- `nfse.settings.company-profiles.update`: PATCH por `companyId`;
  permite parâmetros parciais e/ou troca de PFX, sem transferir a
  propriedade para outra empresa; permissão `update-nfse-settings`.
- `nfse.settings.company-profiles.destroy`: DELETE por `companyId`;
  permissão `delete-nfse-settings`.
- `nfse.settings.company-profiles.cleanup`: POST por `companyId`;
  retoma limpeza interrompida; permissão `delete-nfse-settings`.

Além da permissão de rota, toda mutação exige que o usuário esteja
associado à empresa nativa especificada. Respostas não incluem PFX,
senha, token ou caminho físico local. `fiscal_parameters` aceita apenas
as chaves fiscais explicitamente validadas e não armazena credenciais de
outras integrações. Não há inferência de códigos, alíquotas ou situações
tributárias a partir de nomes comerciais ou aceitação pela SEFIN.

### Ciclo de vida, exclusão e atualização

`provisioning` indica cadastro provisório; `active` indica certificado
persistido (não prontidão jurídica de emissão); `deleting` indica
exclusão ainda não concluída. `cleanup_pending` assinala material
rastreado para recuperação. Uma falha no Vault ou no arquivo mantém a
referência pendente, sem liberar a empresa para recadastro prematuro.
A rotação usa uma referência nova; a versão anterior só é aposentada
após confirmação do novo certificado. Operações fiscais pendentes ou
de resultado ambíguo bloqueiam rotação/exclusão até conciliação.

O `SecretStoreInterface::delete()` atual utiliza o endpoint KV v2
`/data`, que apenas marca a versão atual como excluída. A operação
não garante destruição irreversível das versões anteriores no Vault.
A remoção permanente de todas as versões requer o endpoint `/metadata`
e permissões específicas, ainda não implementados neste contrato.
O operador deve aplicar a política de retenção adequada no OpenBao/Vault.

Nenhuma dessas operações apaga a empresa nativa, faturas, NFS-e autorizadas,
DPS, XML, recibos ou artefatos históricos. As configurações anteriores
`nfse.*` **não são migradas nem reaproveitadas**: recadastre infraestrutura,
certificados e complementos fiscais no novo contrato.

A nova interface pertence à #420 e o consumo do perfil pelos emissores,
filas, recuperação, ADN e WebDAV pertence à #421. **Não interprete um
perfil `active` como autorização para transmitir NFS-e** enquanto
esses consumidores não tiverem sido integrados e testados.

## Dados fiscais e validação

Associe perfil fiscal a cada serviço/fatura conforme os dados da operação.
A classificação dos impostos federais dos itens considera nomes e
identificadores textuais configurados no Akaunting: PIS, COFINS, IRRF, CSLL e
contribuição previdenciária. Use nomes explícitos, por exemplo `IRRF - Serviços`
ou `cod:pis`; códigos numéricos isolados podem ser ambíguos.
Confira o enquadramento tributário com o responsável fiscal.

Na aba fiscal de cada empresa, indique a opção pelo Simples Nacional, o
**regime especial de tributação** da DPS e, para ME/EPP, o **regime de apuração
tributária do Simples Nacional** (`regApTribSN`). Os valores desse último campo
são `1` (tributos federais e ISSQN pelo Simples), `2` (federais pelo Simples,
ISSQN fora) e `3` (ambos fora). A escolha não é deduzida da natureza jurídica:
depende do enquadramento fiscal e competência efetivos e deve ser confirmada
com o responsável tributário. Sem essa informação, a emissão ME/EPP será
bloqueada (E0166). Na opção `1`, informe `regEspTrib=0` (E0175). Alterar a
opção de Simples não apaga silenciosamente escolhas anteriores, mas a emissão
será revalidada. Regimes municipais especiais continuam sujeitos às condições
do município, prestador, serviço e competência (E0176–E0178).

IBS/CBS só deve ser habilitado quando a operação e o contrato de emissão
suportarem os campos requeridos. O módulo não deduz automaticamente códigos
CST, cClassTrib, indicadores da operação e demais condições fiscais.

Para detalhes, consulte [Emissão vigente](emissao-vigente.md) e
[Segurança fiscal](fiscal-safety.md).

## Envio de e-mail e cópias

Nos formulários de emissão e reemissão, preencha **Para** com os destinatários
principais e expanda **Cc** ou **Cco** quando necessário. Destinatários em Cc
são visíveis; os de Cco recebem cópia oculta. Campos aceitam endereços
separados por vírgulas ou ponto e vírgulas.

**Enviar uma cópia para mim** acrescenta o usuário autenticado ao Cco sem
duplicar entregas. Destinatários inválidos bloqueiam a operação antes da
transmissão da NFS-e. Desativar o e-mail mantém a emissão fiscal sem
notificação, independentemente de endereços preenchidos. Assunto, corpo
e anexos seguem as preferências já existentes. Erros de notificação
posteriores à autorização não exigem nova transmissão fiscal.

## Fluxos e documentos

A NFS-e autorizada e a fatura Akaunting possuem estados distintos.
A emissão usa o XML autorizado como fonte fiscal para DANFSe e reconciliação.
O processamento pós-emissão pode utilizar a fila do Akaunting, com proteção
contra duplicação após autorizações ambíguas: [Filas](queue.md).

Documentos e eventos podem ser consultados pelo ADN conforme as permissões
da integração. O módulo não aplica automaticamente aos cadastros alterações
identificadas em consultas somente leitura.

Para dados fiscais de clientes, veja
[Perfil fiscal do tomador](contact-fiscal-profile.md); para relatórios e
consulta de registros por nota, utilize as interfaces próprias de NFS-e.

## Numeração fiscal da DPS — implementação em andamento

O épico #423 ainda não habilitou o sequenciamento unificado. As emissões
existentes **não utilizam** as tabelas de reserva novas. Não iniciar emissões
em produção presumindo que a arquitetura proposta já esteja integrada.

A fundação de reserva usa identidade fiscal do emissor (município de emissão,
tipo e registro federal), ambiente e série; `company_id` permanece apenas
como propriedade/autorização da operação. Novas séries começam **inativas**.
A referência de coordenação não garante unicidade entre bancos independentes:
é indispensável provar que outras instalações e emissores não disputem a
mesma série ou coordenem as reservas em armazenamento transacional comum.
Não cadastrar a referência com dados de clientes, credenciais ou certificados.

A fonte efetiva de números não pode ser o ID local da fatura, o ID de um
grupo ou o recibo cancelado. Qualquer operação iniciada que tenha resultado
ambíguo deve preservar sua DPS para reconciliação exclusivamente por leitura,
sem retransmissão automática. O número autorizado da NFS-e nunca é alterado
por essa política. Consulte o ADR 0003 e o épico #423 antes da ativação.

## Atualização e testes

Siga [Atualização do runtime nfse-php](nfse-php-runtime-upgrade.md).
Para desenvolver e verificar funcionalidades, utilize as suites e workflows
registrados no `composer.json`, `package.json` e
[documentação de testes](testing-ci-budget.md). Dados de teste precisam ser
sintéticos; nenhuma suíte comum deve transmitir notas fiscais reais.
