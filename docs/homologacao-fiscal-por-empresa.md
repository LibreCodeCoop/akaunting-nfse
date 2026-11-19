<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Homologação fiscal por empresa antes de emitir NFS-e

O módulo fornece validação de contratos, cálculo com fatos fiscais confirmados,
rastreabilidade e recuperação de emissões. A configuração tributária de cada
empresa e de cada tipo de operação exige **revisão da contabilidade responsável
pela empresa**. CI verde e autorização pela SEFIN não constituem parecer fiscal.

Esta homologação ocorre durante a implantação ou mudança material do perfil
fiscal, não durante o desenvolvimento de hipóteses de empresas ainda desconhecidas.
Não é necessário submeter cada NFS-e rotineira a nova aprovação individual:
homologue as classes reais de operação e reavalie exceções e alterações.

## Responsabilidades

- **Equipe técnica:** comprovar isolamento por empresa, persistência de valores,
  contrato DPS/XML/DANFSe, integridade financeira, regras gerais documentadas
  e proteção contra retransmissão de resultado ambíguo.
- **Implantador e operador:** levantar os fatos e documentos da operação,
  configurar os perfis fiscais e executar os testes controlados por empresa.
- **Contabilidade da empresa:** confirmar enquadramento, incidência, benefícios,
  bases, alíquotas, retenções, competências e responsabilidades aplicáveis.
  Nenhuma opção ou benefício deve ser deduzido apenas do tipo cooperativo.
- **Responsável autorizado pela empresa:** decidir sobre entrada em produção,
  preservando o registro do aceite e das ressalvas.

## Checklist para uma empresa e seus tipos de operação

1. Identificar a empresa, o ambiente, a competência e **cada tipo de operação
   efetivamente realizado**, incluindo serviço, tomador, município de incidência
   e eventual obrigação de emissão. Não usar a data de criação da fatura como
   prova automática do fato gerador.
2. Confirmar com a contabilidade regime tributário, opção aplicável, serviços
   LC 116, cTribNac, complemento municipal quando necessário, regime especial,
   bases, alíquotas, retenções próprias/na fonte e exceções relevantes. Registrar
   a fonte normativa, versão e vigência por hipótese. Um código nacional válido
   não demonstra a administração municipal do serviço.
3. Conferir no Akaunting os perfis de itens, categoria de receita autorizada,
   associações fiscais de impostos existentes por `company_id + tax_id`,
   dados do prestador e tomador, grupo fiscal e opção de emissão. Não criar
   impostos financeiros nem modificar faturas históricas automaticamente.
4. Em ambiente sintético isolado, exercitar ao menos um caso válido, um caso
   objetivamente inválido e um caso de evidência insuficiente para cada
   tratamento material. Comparar valores da fatura, DPS, XML autorizado de
   fixture/fake SEFIN e DANFSe sem recalcular tributos no renderizador. Validar
   multiempresa, retenções e nenhuma segunda transmissão após resposta ambígua.
5. Quando tecnicamente aplicável, conferir o leiaute ativo no ambiente de
   produção restrita. Resultados ali não são documentos fiscais reais.
   Reconciliações locais não devem registrar como autorizado um XML não
   comprovado pelo órgão emissor.
6. Registrar o aceite da contabilidade para os casos reais e as ressalvas
   antes de autorizar a primeira emissão de produção. A primeira emissão real
   só ocorre por ação humana autorizada, com conferência do resultado e
   conciliação de eventual resultado incerto; nunca para testar um novo layout.
7. Revalidar a configuração quando mudarem serviço, município, tomador,
   regime, tratamento, norma aplicável, implantação do XSD ou responsabilidade
   pela retenção. Preservar os registros dos aceites anteriores.

## Registro privado do aceite

Manter em repositório documental com acesso restrito, **não em issues públicas**:

- Empresa e responsável pela implantação; contador/revisor e data do aceite.
- Classes de operação e competências validadas, fontes oficiais e versões.
- Dados sintéticos utilizados, resultados calculados independentemente e
  comparação fatura → DPS → XML autorizado → DANFSe.
- Pendências e ressalvas, decisões sobre correções e autorização de produção.
- Referências de documentos fiscais reais, quando indispensáveis, protegidas
  por controle de acesso e sem exposição em CI ou logs públicos.

O estado `unverifiable` é uma informação de revisão, não isenção,
inadimplência nem autorização fiscal. Erros objetivos continuam bloqueando
a fronteira de transmissão; pendências fiscais **não** devem impedir o
salvamento ou pagamento financeiro nativo de uma fatura.

Para operação e reconciliação, consulte [Emitir e reconciliar NFS-e](emissao-vigente.md).
Para fontes e regras gerais utilizadas nos testes, consulte a
[matriz normativa versionada](fiscal-normative-review.md).
