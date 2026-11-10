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
Para quem ainda não possui um servidor de segredos, há um
[exemplo opcional de implantação OpenBao](openbao-deployment.md). A utilização
de Vault/OpenBao externo permanece suportada, sem Docker obrigatório.
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
Ao alterar o endereço do Vault, informe novamente o token ou o par AppRole.
Campos de credenciais omitidos só preservam valores quando o servidor não muda.
Se faltarem credenciais para o novo servidor, a alteração é recusada e o
registro global anterior permanece intacto.

O diagnóstico sob demanda não armazena nem modifica certificados. Verifica
saúde, autenticação, KV v2 e capacidade de leitura/criação/atualização
em um caminho sintético `pfx/__nfse_capability_probe__`. O resultado
`operational` descreve apenas o instante da verificação e não garante
operações futuras. A consulta não é requisito para listar documentos/perfis
pré-existentes. Endereços externos exigem HTTPS com certificado válido.
Apenas `http://openbao:8200` (com ou sem barra final) é aceito sem TLS,
para o serviço da rede Docker interna do exemplo de implantação. Não
publique a porta desse serviço e não utilize essa exceção para tráfego
entre hosts ou redes não confiáveis. O teste não envia NFS-e nem utiliza
SEFIN/ADN.

## Numeração DPS e coordenação entre instalações

A identidade fiscal de uma DPS é formada pelo município emissor, inscrição
federal e seu tipo, ambiente (produção ou produção restrita), série e número.
O identificador da fatura Akaunting ou do grupo fiscal **não** é número de DPS.
A mesma operação reutiliza a reserva; substituição e reemissão de uma nota
cancelada constituem novas operações, com numeração própria e vínculo ao
documento original.

**Não habilite emissão** antes de comprovar exclusividade de série perante
outros emissores, numeração histórica máxima e coordenação de todas as
instalações que compartilhem a identidade fiscal. Configure uma série decimal
explícita de `00001` a `49999` para emissão por API no campo `dps_series` do
perfil fiscal; ela não é ativada automaticamente. O FAQ oficial de setembro
de 2026 distingue essa faixa das séries reservadas a outros canais:
https://www.gov.br/nfse/pt-br/biblioteca/perguntas-e-respostas/perguntas-e-respostas-da-nfs-e
O FAQ é material de orientação, não substitui os esquemas e regras oficiais do
ambiente de destino. Cadastre a série local
`nfse_dps_series` como `active` apenas após verificar identidade,
`coordination_mode=shared_database` e correspondência da referência
criptograficamente aleatória com o coordenador central.

O painel de diagnóstico NFS-e apresenta a série, o último número reservado
e o próximo número DPS para o perfil fiscal da empresa selecionada. Com
permissão `update-nfse-settings`, é possível **avançar excepcionalmente**
o próximo número, após verificar o histórico e informar justificativa. O campo
representa o **próximo** número: para que a próxima reserva seja `51`, o
contador persistido será `50`. O sistema nunca permite diminuir ou repetir
o próximo número. Os dois contadores do mesmo MySQL são atualizados na
mesma transação e o ajuste registra empresa, operador, valores anterior/novo
e justificativa. Essa operação não reserva, transmite, autoriza ou reenvia DPS.

Para série inédita e comprovadamente exclusiva, o próximo número padrão
é `1`, sem necessidade de ajuste. Série ainda não provisionada ou sem
evidência de histórico aparece como indisponível; o ajuste não substitui
provisionamento ou autorização de emissão. Em caso de dúvida sobre uma
lacuna, restauração de backup ou uso anterior da mesma série, suspenda
emissões e reconcilie evidências antes de avançar. Um número ignorado
não é recuperado automaticamente.

O coordenador utiliza **o mesmo banco MySQL do Akaunting**, com as tabelas
de autoridade DPS criadas pela migração padrão do módulo. Não existe segundo
banco, segundo Akaunting nem microsserviço. Uma conexão PDO independente
confirma irrevogavelmente a alocação e o primeiro POST antes das transações
do diário fiscal. O termo interno `coordination_mode=shared_database`
continua suportado apenas como valor de contrato preexistente; refere-se ao
mesmo banco compartilhado pelos processos PHP e fila.

Para operar, configure fora do banco apenas a identidade estável da
instalação e sua geração:

- `NFSE_DPS_INSTALLATION_ID`: UUID exclusivo da instalação.
- `NFSE_DPS_INSTALLATION_GENERATION`: geração positiva aprovada.
- `NFSE_DPS_COORDINATOR_USER` e `NFSE_DPS_COORDINATOR_PASSWORD`:
  opcionais, em conjunto, para acesso com privilégios mínimos **ao mesmo
  banco Akaunting**. Se omitidas, as credenciais MySQL da aplicação são
  utilizadas; não existe `NFSE_DPS_COORDINATOR_DSN` externo.

A criação de tabelas e de um perfil fiscal não ativa emissão. A série DPS
precisa ter evidência independente de exclusividade e maior número já
consumido; cadastre a mesma referência aleatória e o mesmo piso verificado
nas tabelas locais e de autoridade, e ative a geração autorizada apenas
após testes e autorização operacional. Todos os passos estão no
[procedimento de coordenação e restauração](dps-distributed-coordination.md).

Consulte [a arquitetura e a matriz de falhas](dps-distributed-coordination.md)
para gestão de failover, rollback, PITR, evidência de posse da série,
reconciliação do histórico e recuperação. O coordenador protege somente
emissores participantes; o uso da mesma série por um emissor externo continua
sendo risco operacional e impede habilitação segura.

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

### Administração pela interface

Na seção **NFS-e desta empresa**, a interface exibe somente o perfil da
empresa selecionada no Akaunting. Alterne de empresa pelo seletor nativo
do Akaunting. A infraestrutura OpenBao é global, mas o certificado e os
parâmetros fiscais pertencem exclusivamente ao identificador dessa empresa.

Sem perfil, a ação **Configurar NFS-e** inicia o cadastro diretamente para a
empresa selecionada. Se o Vault não estiver operacional, a ação apresenta
o requisito pendente e abre a configuração de infraestrutura; não permite
registrar certificados. Com perfil existente, o operador edita ou exclui
sua configuração, sem cadastrar um segundo perfil para a mesma empresa.

O cadastro exige enviar PFX/P12 com sua senha e informar complementos fiscais.
A edição não permite transferir o perfil para outra empresa.
Um novo certificado e sua senha são necessários somente para a rotação.
Pendências de preenchimento devem ser corrigidas antes de tentar emitir, mas
preencher os campos não implica autorização fiscal de transmissão.

A confirmação de estabelecimento para avaliação de incidência municipal é uma
declaração explícita do operador, registrada no perfil da empresa junto com
identidade CNPJ do certificado, município IBGE, autor e data/hora. Alterar o
município invalida a confirmação, mesmo que o formulário a envie marcada;
após conferir a nova identidade, confirme novamente em uma edição separada.
Não há leitura da confirmação fiscal antiga `nfse.provider_establishment_*`
na avaliação municipal.

A seção **Infraestrutura** concentra Configurar/Editar e o diagnóstico recolhido.
O diagnóstico verifica saúde, autenticação, KV v2 e permissões genéricas em
certificados, identificando a última verificação feita na página. O backend
continua verificando o caminho específico de cada novo certificado. Se o Vault
ficar indisponível, novos cadastros ficam bloqueados, enquanto a listagem dos
perfis existentes permanece consultável.

A disponibilidade de **Configurar NFS-e** depende da ausência de perfil na
empresa selecionada. O diagnóstico do Vault determina se o editor fiscal
pode abrir ou se o operador precisa configurar a infraestrutura. Uma falha de
consulta à listagem oculta os metadados carregados anteriormente e exige
nova autorização. A edição preserva o vínculo original e mantém o certificado
existente quando não há novo PFX/P12. Erros de validação ficam no formulário,
sem expor credenciais; ações de gravação e limpeza sinalizam processamento
e impedem envios duplicados enquanto estiverem em curso.

Excluir requer confirmação: a empresa do Akaunting e os documentos fiscais
históricos permanecem intactos. Operações fiscais pendentes podem impedir a
exclusão; se a limpeza de segredos for interrompida, use **Retomar limpeza**
antes de tentar recadastrar a empresa. Depois da exclusão efetiva, a empresa
volta à lista elegível.

### Contrato HTTP administrativo

A interface utiliza estas rotas nomeadas. A consulta permanece possível
mesmo durante indisponibilidade temporária do Vault:

- `nfse.settings.company-profiles.index`: GET; retorna `profiles`
  (metadados sem credenciais) e `eligible_companies` somente da empresa
  selecionada, quando ainda sem perfil; permissão `read-nfse-settings`.
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
associado à empresa nativa e que a empresa ativa resolvida pelo middleware
IdentifyCompany do Akaunting seja a mesma do perfil. Acesso a outra empresa não permite
manipulá-la pela URL da empresa atual. Respostas não incluem PFX,
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
certificados e complementos fiscais no novo contrato. Rotas antigas que
gravavam configuração fiscal geral, tributos federais globais ou certificados
por CNPJ retornam HTTP 410 (`Gone`). O endpoint de inspeção PFX (somente
leitura) permanece disponível. A configuração WebDAV usa as preferências
nativas da empresa Akaunting ativa, separada dos parâmetros do emissor.

A infraestrutura e os perfis fiscais são isolados por empresa. Um perfil
`active` não autoriza transmissão por si só: emissão, recuperação e filas
permanecem condicionadas à validação fiscal, certificado, Vault e identidade
DPS conforme o fluxo e o ambiente em uso.

## Associações fiscais de impostos existentes

Em **Configurações NFS-e → Revisar associações fiscais dos impostos existentes**,
o operador pode consultar os impostos nativos da empresa ativa e confirmar
explicitamente identidade fiscal (ISSQN, PIS, COFINS, CSLL, IRRF, CP, IBS ou CBS),
papel (devido, retido ou estimativa de transparência), vigência e referência
documental. A ação exige permissão `update-nfse-settings`; leitura requer
`read-nfse-settings`. Cada confirmação registra seu autor e não altera
`taxes`, itens, valores financeiros nem documentos fiscais autorizados.

Associações são revisões datadas: não podem sobrepor o período de outra
classificação para o mesmo `tax_id`; repetir a mesma confirmação por seu
autor é idempotente. Para alterar uma classificação vigente, selecione
**Retificar** na revisão correspondente, informe uma nova vigência inicial
(hoje ou futura, posterior ao início da revisão original), a identidade,
o papel e uma nova referência documental, e confirme. O sistema encerra
a vigência anterior no dia imediatamente anterior à nova e registra outra
revisão com seu próprio autor e fonte. A operação é atômica e idempotente;
um conflito com outra revisão não altera o histórico.

Não é possível retificar retroativamente um período já transcorrido. A
identidade, a fonte e o autor da confirmação anterior permanecem preservados.
Faturas, valores contábeis e XML/PDF autorizados não são alterados.

Uma associação comprova somente que um operador atribuiu identidade e papel
ao imposto existente. Não comprova incidência, alíquota, base, retenção ou
obrigação legal. Impostos homônimos não são mapeados automaticamente,
e IBS/CBS não são inseridos nas linhas de faturas por conta da exigência
documental na NFS-e. Para alteração da alíquota ou criação de imposto, use
o cadastro nativo de impostos do Akaunting com orientação contábil.

## Dados fiscais e validação

Associe perfil fiscal a cada serviço/fatura conforme os dados da operação.
Códigos de serviço e tributação nacional utilizados nos grupos devem vir dos
perfis fiscais dos itens da fatura. A configuração antiga `nfse.*` não preenche
automaticamente classificações ausentes; complete o cadastro dos itens antes da
emissão. A ausência de classificação não autoriza substituir códigos fiscais
por valores de outra empresa. Isso inclui os métodos administrativos de
emissão manual, que não devem consultar `nfse.item_lista_servico`,
`nfse.codigo_tributacao_nacional` nem `nfse.aliquota` descontinuados.
Para os impostos **existentes** no Akaunting, confirme em separado o
vínculo fiscal por `company_id + tax_id`, papel e vigência, sem inferir
identidade a partir de nome ou percentual. Uma associação confirmada
não comprova a incidência, o cálculo ou a retenção legal na competência.

Alguns caminhos legados de agregação ainda interpretam nomes textuais
de impostos; **isso não constitui evidência tributária válida** e está
sob revisão de #428/#429. Não altere nomes ou alíquotas para forçar
uma classificação silenciosa. Confira o enquadramento com o responsável
fiscal antes da transmissão.

Na aba fiscal de cada empresa, selecione explicitamente a situação do prestador
no Simples Nacional (não optante, MEI ou ME/EPP). Ausência ou código inválido
permanece desconhecido: a interface não escolhe automaticamente "não optante",
e nenhuma emissão ou reemissão pode presumir esse status. Confirme ainda o
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
Os anexos WebDAV enviados por e-mail utilizam apenas caminhos já gravados no
recibo; não são inferidos de templates legados quando não há registro, evitando
acesso a arquivos de outra empresa que possua CNPJ coincidente.

O assunto e o corpo podem ser salvos como **padrão geral** ou **somente para o
cliente**. A preferência do cliente é isolada por empresa e contato, prevalece
sobre o padrão geral nas próximas faturas daquele contato e aceita as tags
disponibilizadas no formulário, como {nfse_number}, {invoice_amount_due}
e {invoice_due_date}. A opção **Salvar modelo para este cliente sem emitir**
grava exclusivamente a preferência de mensagem: não transmite DPS, envia
e-mail, altera fatura ou muda o modelo global. Assunto, corpo e botão de salvar
permanecem acessíveis mesmo com o envio de e-mail desativado; os destinatários
só aparecem quando o envio está habilitado. Para anexos, a seleção continua
sendo conferida no envio; o modelo não anexa automaticamente arquivos externos,
como relatórios de horas, que precisam ser selecionados manualmente.

O corpo dos e-mails da NFS-e utiliza **CKEditor 5** para formatação básica
(negrito, itálico, sublinhado, listas e links) e edição com teclado e seleção
nativos. O editor não envia mensagens sozinho. As tags dos modelos existentes
continuam como texto editável e são resolvidas no envio. A versão GPL é servida
localmente, sem CDN nem componentes premium; os avisos de direitos autorais
acompanham os arquivos distribuídos.

Para gerar novamente os arquivos estáticos do editor ao desenvolver o módulo,
execute `npm ci --include=dev` e `npm run build:email-editor`. Os arquivos
resultantes em `Resources/assets/vendor/ckeditor5/` fazem parte da versão
implantada; a instalação de produção não executa npm e não precisa acessar
terceiros para carregar o editor.

## Fluxos e documentos

A NFS-e autorizada e a fatura Akaunting possuem estados distintos.
A emissão usa o XML autorizado como fonte fiscal para DANFSe e reconciliação.
O processamento pós-emissão pode utilizar a fila do Akaunting, com proteção
contra duplicação após autorizações ambíguas: [Filas](queue.md).
Novos caminhos de arquivamento WebDAV são isolados por
`companies/{company_id}/` por padrão, mesmo quando empresas distintas
usam o mesmo CNPJ. Para um destino WebDAV dedicado a uma única empresa,
o administrador pode selecionar **Respeitar exatamente a pasta configurada**,
sem prefixo adicional. Em pastas compartilhadas entre empresas, mantenha
a opção de isolamento para evitar colisões. O arquivamento e a recuperação
usam a mesma regra. Caminhos históricos não são movidos automaticamente.

Documentos e eventos podem ser consultados pelo ADN conforme as permissões
da integração. O módulo não aplica automaticamente aos cadastros alterações
identificadas em consultas somente leitura.

Para dados fiscais de clientes, veja
[Perfil fiscal do tomador](contact-fiscal-profile.md); para relatórios e
consulta de registros por nota, utilize as interfaces próprias de NFS-e.

## Numeração fiscal da DPS e transição do portal nacional

O sequenciamento está integrado à emissão: novas operações utilizam a reserva
persistente, o diário de tentativas já existente e o bloqueio central
irreversível da primeira transmissão. Uma série cadastrada, mesmo marcada
como ativa no banco local, **não autoriza** emissão sem coordenador compartilhado
válido, acesso autorizado e verificação fiscal. A configuração operacional e
os limites estão descritos em [Numeração DPS e coordenação entre
instalações](#numeração-dps-e-coordenação-entre-instalações).

Ao substituir a emissão pelo **Portal Nacional Web** pela emissão via API do
Akaunting, não copie a numeração das notas já autorizadas pelo portal nem
reescreva os seus documentos, XMLs ou histórico. São canais com faixas
distintas de série: o aplicativo próprio utiliza `00001` a `49999`; a faixa
do emissor web nacional é diferente. Consulte o
[FAQ oficial da NFS-e](https://www.gov.br/nfse/pt-br/biblioteca/perguntas-e-respostas/perguntas-e-respostas-da-nfs-e)
e confirme as regras efetivamente implantadas no ambiente de destino.

A série própria só pode começar no número `1` quando houver evidência de que
ela **nunca foi usada** para o mesmo prestador e ambiente. Verifique
eventual emissão anterior via integração de terceiros, registre a maior DPS
historicamente usada e configure o piso atestado e o contador global de acordo.
Se não existir uso anterior comprovado, o piso pode ser `0`. A existência de
notas emitidas pelo portal, em outra série, não implica consumo da série própria.

Antes de ativar qualquer série, obtenha evidência de exclusividade,
provisione e faça backup do primário MySQL, valide as gerações das instalações
e os privilégios mínimos, verifique a compatibilidade do XSD e conclua os testes
com fake SEFIN e o aceite em produção restrita. O procedimento detalhado de
falhas, restauração e revogação está em
[Coordenação distribuída da DPS](dps-distributed-coordination.md).
Uma resposta de transmissão desconhecida exige reconciliação por consulta,
**nunca novo POST automático**. A primeira emissão real requer autorização
operacional independente.

## Revisão operacional de faturas no fechamento

Em **NFS-e → Fechamento**, os totais fiscais e a exportação CSV existentes
continuam calculados exclusivamente a partir de recibos e snapshots de XML
autorizado. Uma seção separada apresenta faturas comerciais do mês informado,
selecionadas pelo campo financeiro `issued_at`, em páginas de 30 registros.

A revisão indica documento fiscal não vinculado, resultado em recuperação,
documentos cancelados que exigem revisão, grupos parcialmente vinculados ou
documento autorizado vinculado. A data financeira não comprova o fato gerador
nem o prazo de emissão. Nenhum desses estados declara atraso, dispensa,
regularidade jurídica ou omissão fiscal. Nota emitida externamente ainda
não vinculada precisa ser conciliada antes de qualquer nova transmissão.
A consulta não envia DPS, não corrige valores e não modifica o histórico.

Atraso somente poderia ser declarado se a operação, o fato gerador e sua
data, a regra vigente e seu prazo, bem como a ausência de documento válido
estivessem comprovados. A DPS nacional segue o prazo de conversão previsto
na legislação municipal aplicável, que não pode ser presumido com base em
regras antigas de RPS ou nas datas documentais da reforma IBS/CBS.

A exportação **Evidências IBS/CBS (CSV)** apresenta, por recibo, valores
constantes do snapshot de XML autorizado com seu SHA-256 registrado e estados
independentes para IBS e CBS: informado, zero explícito, campo ausente, valor
inválido, snapshot indisponível ou documento não ativo. Documentos cancelados
e substituídos não compõem totais. Ausência não equivale a zero nem a dispensa.

A seção IBS/CBS do fechamento é alimentada pelo mesmo recurso JSON que a
interface já utiliza, e a exportação também está disponível pela API nativa.
Os totais são SOMENTE valores documentais observados, não débitos apurados,
créditos utilizáveis, saldo a recolher ou declaração apresentada.
São necessárias evidências de entradas, créditos, ajustes e de cumprimento das
obrigações específicas para uma apuração fiscal. O registro não envia notas,
altera históricos nem substitui a plataforma oficial de apuração.

No ADN, documentos classificados como recebidos podem exibir evidências
documentais IBS/CBS no preview de revisão, com elegibilidade de crédito
**não verificável** até existir fundamento e comprovação. Documentos apenas
intermediados não geram candidatos a crédito. Nenhum valor é lançado
automaticamente como crédito fiscal, e nenhum pagamento é considerado quitado.

Acesso ao fechamento/CSV requer empresa ativa e a permissão
`read-sales-invoices`.

## Atualização e testes

Siga [Atualização do runtime nfse-php](nfse-php-runtime-upgrade.md).
Para desenvolver e verificar funcionalidades, utilize as suites e workflows
registrados no `composer.json`, `package.json` e
[documentação de testes](testing-ci-budget.md). Dados de teste precisam ser
sintéticos; nenhuma suíte comum deve transmitir notas fiscais reais.

## Validação nacional e consulta municipal de itens

Ao editar um item, o Akaunting apresenta a validação nacional do perfil e,
se houver contexto fiscal da empresa, a consulta municipal separadamente.
O código LC 116 identifica o subitem de serviço, o `cTribNac` tem seis
dígitos, o complemento municipal tem três, e a combinação do código nacional
com o complemento forma o código de serviço de nove dígitos. A NBS é uma
classificação distinta, não deduzida automaticamente da LC 116.

A seção **Detalhes da consulta municipal** mostra o município IBGE, a
competência, o ambiente e o serviço usados na busca. Quando há registro,
a data de coleta aparece junto à mensagem da fonte. Um registro consultado
em data anterior pode precisar de nova conferência, mas sua idade, sozinha,
não prova que o município rejeitou a classificação.

A consulta de parâmetros e o catálogo nacional são orientativos. Não
comprovam autorização da NFS-e, dispensa fiscal ou rejeição da SEFIN.
Uma consulta indisponível ou inconclusiva não deve ser convertida em
rejeição oficial. Se a SEFIN rejeitar uma emissão (por exemplo, E0312),
examine o código nacional, o complemento municipal, o município de
incidência e a competência no **painel da NFS-e da fatura**. A mensagem
oficial pertence à tentativa de emissão, não à validação do cadastro do item.
Se o resultado do POST for incerto, use a recuperação de DPS existente;
não faça outra transmissão para testar.

O preflight no backend continua sendo a fronteira de decisão sobre
tentar emitir; esta tela não adiciona bloqueio fiscal ao cadastro do
item ou ao ciclo financeiro. Consulte também
[ADR de evidências fiscais](adr/0002-fiscal-assessment-contract.md).
