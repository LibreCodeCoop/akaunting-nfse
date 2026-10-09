<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Emissão NFS-e vigente — checklist e recuperação segura

Este procedimento é para **emitir a NFS-e Nacional hoje pelo Akaunting**,
usando o contrato DPS v1.01 publicado para produção. Não pressupõe liberação
do layout NT009 v1.01. A homologação e a produção devem usar o ambiente e
certificado correspondentes; uma nota de homologação não é documento fiscal
autorizado em produção.

## Preparação

1. Em **NFS-e → Configurações**, conferir o CNPJ do prestador, município IBGE,
   ambiente (produção para a nota fiscal real), certificado ICP-Brasil
   aplicável, arquivo PFX e senha no OpenBao/Vault. A prontidão operacional
   deve indicar as capacidades efetivamente presentes. Não compartilhar
   certificado, senha, token ou XML fiscal com terceiros.
2. Na fatura já cadastrada, conferir o tomador, competência efetiva da DPS,
   valores e o perfil fiscal dos itens. Usar código LC 116, cTribNac nacional
   de seis dígitos e complemento municipal de três dígitos **do grupo fiscal
   selecionado**, não do perfil padrão de outro item.
3. Verificar a incidência e a parametrização municipal para aquela operação
   e competência. Uma taxa/404/snapshot não atesta autorização e não deve
   impor bloqueio genérico; rejeição oficial como E0312 pertence àquela DPS
   e deve ser resolvida corrigindo o enquadramento fiscal com fundamento.
4. Respeitar a prontidão IBS/CBS conforme regime, tipo de serviço e data de
   competência. No leiaute suportado, habilitar o grupo apenas nos cenários
   tecnicamente representados, com `cIndOp`, `indDest`, `CST` e
   `cClassTrib` fornecidos pelo responsável fiscal. Não inventar
   classificação ou alíquota para passar no validador.

## Emissão e resposta

A ação nativa de emissão usa uma DPS determinística, a autenticação mTLS e
`POST /nfse`. A resposta 201 do contrato SEFIN contém
`chaveAcesso` e `nfseXmlGZipB64` — **não necessariamente contém
`nNFSe` no JSON**. A biblioteca `nfse-php` agora obtém
`NFSe/infNFSe/nNFSe` do XML autorizado.

A NFS-e **só é tratada como autorizada** se houver chave de acesso e número.
Uma resposta 2xx incompleta, falha de transporte ou dúvida após o POST não é
autorização nem rejeição confirmada. O histórico fica `ambiguous` e a
recuperação consulta **DPS → chave de acesso → NFS-e**, sem transmitir a
mesma operação outra vez automaticamente.

Se ocorrer rejeição oficial estruturada, consultar o código e a mensagem
sanitizada no histórico fiscal e corrigir a configuração de origem. Um E0312
exige revisar código, complemento, município de incidência e competência;
não deve bloquear todas as operações futuras do município.

## Se houver urgência operacional

O portal oficial de emissão
https://www.nfse.gov.br/EmissorNacional é uma alternativa quando o
contribuinte está habilitado para o emissor público. Uma nota autorizada fora
do Akaunting deve ser reconciliada no controle contábil/fiscal antes de
qualquer nova tentativa, para evitar duplicidade. Não reenviar a mesma DPS
às cegas após dúvida sobre a resposta.

## Limites verificados

- **Emissor em uso:** `NfseClient::emit(DpsData)` e XSD de produção
  `NFSe-ESQUEMAS_XSD-v1.01-20260209`. O pacote anunciado para
  produção restrita em julho de 2026 não equivale automaticamente ao de
  produção.
- **NT009 v1.01 / Anexo VI v1.04.01:** artefatos publicados, mas sem
  comprovação, nesta revisão, de XSD NT009 implantado/ativo para emissão
  pelo contribuinte. A pré-visualização estrutural não é enviável.
- **Adoção do runtime:** este PR fixa a biblioteca assinada
  `443465b05cef00f159bb412db77a0c5d3c3bb089`, que corrige o retorno oficial sem alterar o DPS emitido.
  Não fazer deploy deste módulo antes de o PR da biblioteca estar
  incorporado e de os testes de composição escopada passarem.
- **Teste real:** nenhum certificado de contribuinte ou requisição
  autenticada a produção foi utilizado. Somente o retorno real do ambiente
  oficial pode confirmar uma nota emitida.

Fontes:
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc
- https://www.nfse.gov.br/EmissorNacional
