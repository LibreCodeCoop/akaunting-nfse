<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Fontes dos tributos aproximados ao consumidor

Os totais aproximados usados para transparência da carga tributária
(Lei 12.741/2012 e Decreto 8.264/2014) são **informações de caráter
estimativo**, não a apuração do ISSQN, retenções ou IBS/CBS. A alíquota
efetiva do ISSQN de uma fatura não é usada como estimativa municipal.

## Cadastro e aplicação

Em **NFS-e → Configurações → Federal**, use
**Gerenciar fontes e versões**. Cada revisão exige:

- empresa ativa no Akaunting, código nacional do serviço `cTribNac`
  com seis dígitos e enquadramento do prestador (não optante, MEI ou
  Simples Nacional);
- competência inicial e, se aplicável, final;
- natureza da origem, nome, versão e referência documental verificável
  do estudo, tabela ou declaração conservada pelo contribuinte;
- três percentuais explicitamente informados: federal, estadual e
  municipal. `0.00` só deve ser declarado quando sustentado pela fonte.

Novas versões são cadastradas sem sobrescrever as anteriores. Para uma
nova DPS, a seleção considera empresa, código do serviço, regime e
competência da **operação fiscal**. Estimativas não são copiadas
automaticamente dos impostos ou retenções da fatura. Os registros são
referências **fornecidas pelo operador**: o módulo não verifica a
idoneidade da instituição, a licença do estudo nem a aplicabilidade da
estimativa à operação concreta. Esses requisitos devem ser examinados
pelo responsável tributário.

O cadastro permite registrar uma estimativa com fonte, declaração
fundamentada do contribuinte ou zero explicitamente documentado. Não
classifique como zero confirmado a ausência de fonte.

## Ausência, XML e obrigação de transparência

Quando não há dados disponíveis para a identidade fiscal da DPS, o
sistema preserva o estado **não informado**. O serializador do
`nfse-php` pode representar isso no grupo `totTrib` por
`indTotTrib=0`, conforme a alternativa do XSD v1.01. Essa possibilidade
**não é autorização fiscal nem comprovação** da obrigação de
transparência. O dever de informar ao consumidor, seus meios
admitidos e as regras específicas do MEI e do Simples Nacional dependem
da Lei 12.741/2012, do Decreto 8.264/2014 e da operação concreta.

Quando os três percentuais estão presentes, a DPS transporta
`pTotTribFed`, `pTotTribEst` e `pTotTribMun`, inclusive zeros
expressamente declarados. Não se completam porcentagens ausentes com
`0.00` artificiais para satisfazer o schema.

O DANFSe é gerado a partir do **XML autorizado**, sem recalcular
estimativas a partir do ISS efetivo. O PDF arquivado não é modificado
automaticamente quando a fonte muda.

## Histórico e recuperação

O histórico de tentativas registra o resultado da seleção da fonte
(sem fonte, estimativa/declaração ou zero documentado), referência,
versão e competência. Registros antigos que não armazenavam esses
metadados são exibidos como **não registrados**, e não como
`sem fonte`. A consulta do histórico exige a permissão de leitura da
fatura e o contexto da empresa.

Depois da autorização, o XML fiscal é armazenado sem normalizar seus
bytes e seu snapshot preserva os valores informados. Se uma consulta
posterior retornar conteúdo diferente do XML já arquivado, o módulo
solicita reconciliação explícita e não regrava o documento histórico.

Uma nova emissão após cancelamento ou substituição é uma **nova
operação fiscal** e pode selecionar a fonte aplicável à respectiva
competência. Uma resposta ambígua após POST exige consulta e
reconciliação da DPS existente, não nova transmissão.

A atualização do módulo e de sua biblioteca não altera
retroativamente XMLs autorizados nem PDFs já arquivados. Consulte
[segurança fiscal](fiscal-safety.md) e
[operação e configuração](operacao-e-configuracao.md).

Fontes normativas:
[Lei 12.741/2012](https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2012/lei/l12741.htm)
e [Decreto 8.264/2014](https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/decreto/d8264.htm).
