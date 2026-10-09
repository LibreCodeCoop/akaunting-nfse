<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Emitir e reconciliar NFS-e Nacional

Este roteiro descreve a emissão do contrato DPS efetivamente suportado
pelo módulo. Uma publicação NT009 ou um XML de pré-visualização não
autoriza trocar o leiaute transmitido em produção.

## Antes de emitir

1. Em **NFS-e → Configurações**, confirme ambiente, prestador, município
   IBGE, certificado ICP-Brasil correspondente e secret store configurado.
   Não exponha PFX, senha, token nem XML fiscal.
2. Na fatura, revise tomador, competência da DPS, valores e perfil fiscal
   do grupo selecionado: item LC 116, cTribNac de seis dígitos e, se
   aplicável, complemento municipal de três dígitos.
3. Verifique os requisitos municipais e IBS/CBS aplicáveis à operação.
   Uma consulta de alíquota/404/cache não é autorização ou rejeição da
   SEFIN. Não escolha códigos ou regimes apenas para satisfazer o validador.
4. Se o módulo indicar grupo fiscal não suportado ou campos obrigatórios
   ausentes, corrija a operação antes de transmitir.

## Confirmar o resultado

A emissão envia a DPS identificada ao endpoint oficial `POST /nfse`.
Um sucesso fiscal exige evidências oficiais suficientes, incluindo
chave de acesso, XML autorizado e número da NFS-e. O número pode ser
extraído de `NFSe/infNFSe/nNFSe` quando não constar no JSON da resposta.

Se o POST terminar em timeout, falha de transporte ou resposta incompleta,
o resultado é **ambíguo**. Recupere primeiro a DPS por seu identificador,
consulte a chave e a nota correspondente e reconcilie o registro local.
Nunca reenviar automaticamente a mesma operação enquanto houver dúvida.

Uma rejeição estruturada, como E0312, deve ser analisada no contexto
da DPS (código, município e competência), não convertida em proibição
para todas as futuras notas do contribuinte. Erros de geração de DANFSe,
WebDAV ou email após autorização não anulam a nota emitida.

## Alternativa oficial

Quando o contribuinte estiver habilitado, o
[Emissor Nacional](https://www.nfse.gov.br/EmissorNacional) pode servir
como alternativa operacional. Reconcilie no sistema uma nota emitida
fora do Akaunting antes de nova tentativa.

Fontes técnicas e ambientes:
- [Documentação de produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual)
- [Documentação de produção restrita](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita)
- [Documentação RTC](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc)

Para implantação e rollback da biblioteca veja
[atualização do runtime](nfse-php-runtime-upgrade.md).
