<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Avaliação fiscal antes da emissão de NFS-e

O módulo avalia a **DPS final** e os fatos fiscais persistidos no Akaunting
antes do primeiro POST à SEFIN. A mesma fronteira de emissão é utilizada por
emissão manual, API, automação, filas, lotes, grupos fiscais, reemissão e
substituição. O resultado de uma transmissão autorizada não substitui a
comprovação jurídica da operação.

## Datas diferentes, evidências diferentes

- **Data comercial**: `documents.issued_at` identifica a emissão da fatura.
- **Competência declarada da DPS**: `dataCompetencia` é lida da DPS final.
  O fluxo padrão pode inicializá-la com `issued_at`; isso não prova o fato
  gerador nem transforma a data comercial em declaração jurídica.
- **Data do fato gerador**: somente uma evidência explícita e vinculada à
  empresa, fatura e grupo fiscal pode preenchê-la. Sem evidência, permanece
  desconhecida e a decisão RTC é `unverifiable`.

Na tela da fatura, use **Data do fato gerador → Registrar ou corrigir
evidência**. Selecione o grupo fiscal, informe a data, a referência do
documento ou ordem de serviço e confirme a declaração. A referência não é
enviada à SEFIN. As declarações são acrescentadas ao histórico, sem
reescrever as anteriores. Não copie `issued_at` automaticamente.
A referência é uma **declaração auditável do operador**, não uma verificação
independente do documento. A decisão RTC depende da veracidade dessa
declaração e deve ser revisada quando houver dúvida sobre a operação.

A alteração é impedida enquanto houver tentativa de emissão pendente ou
ambígua que precise de recuperação. Uma NFS-e autorizada e seus XML/PDF
arquivados não são modificados.

## API nativa e permissões

Com autenticação e contexto de empresa do Akaunting:

- `GET /{company_id}/{api_prefix}/nfse/invoices/{invoice}/fiscal-assessment`:
  prévia **somente do calendário RTC**, por grupo, com data atestada e
  diagnósticos. A resposta indica `final_dps_checked: false`; não autoriza
  emissão nem consulta/transmite à SEFIN. Requer `read-sales-invoices`.
- `POST /{company_id}/{api_prefix}/nfse/invoices/{invoice}/fiscal-event-evidence`:
  registra evidência com `fiscal_group_key`, `taxable_event_date` no formato
  `YYYY-MM-DD`, `source_reference` e `acknowledge: true`. Requer
  `update-sales-invoices`, operador autenticado e empresa ativa.
- `POST /{company_id}/{api_prefix}/nfse/invoices/{invoice}/emit` e demais
  operações fiscais continuam usando o mesmo journal e a mesma DPS final.
  A prévia nunca substitui a avaliação feita imediatamente antes do POST.

O histórico `emission-attempts` conserva, para novas tentativas, o ID
da evidência utilizada e um resumo imutável da avaliação feita antes do
primeiro POST. Uma tentativa antiga sem esse campo aparece como
`scope: unrecorded`, nunca como comprovadamente conforme.

Os diagnósticos expõem códigos estáveis e campos afetados, sem XML, CPF,
CNPJ ou referência documental da evidência. `unverifiable` não significa
isenção, infração nem autorização. `unsupported` indica modalidade ou
combinação não suportada. As decisões normativas conclusivas exigem fonte
legal versionada.

## Política do primeiro POST

A avaliação compartilhada reutiliza a política RTC, associações fiscais
confirmadas por empresa e `tax_id`, regras `regEspTrib/regApTribSN`,
admissibilidade municipal, proteção IBS/CBS e valores efetivamente observados
para NT007. A consulta municipal não verificável é distinta de uma rejeição
oficial. A ausência de retenção comprovada não vira `0.00`.

Para NT007, `vRetCSLL` representa a soma **selecionada e comprovada**
das retenções PIS/COFINS/CSLL segundo `tpRetPisCofins`. Uma operação sem
retenção CSLL não deve receber CSLL inventada. PIS/COFINS devidos pelo
prestador não são retenções. Uma divergência entre a DPS final e as
observações atuais bloqueia o primeiro POST.

A avaliação ocorre antes de abrir uma tentativa imutável e novamente antes
da reivindicação irreversível do primeiro POST. Mudanças de grupo, montante,
associação ou evidência entre essas etapas impedem a transmissão. Tentativas
já autorizadas ou ambíguas mantêm o fluxo de recuperação somente de leitura;
nenhum diagnóstico novo autoriza retransmissão cega.

## Limites e implantação

A decisão fiscal não prova, sozinha, incidência, base de cálculo, condição
cooperativista especial, opção tributária ou direito a benefício. Quando
faltarem provas, o diagnóstico conserva a incerteza; apenas bloqueios
explícitos impedem a transmissão. A obrigação legal pode exigir avaliação
profissional além das verificações técnicas.

Antes de usar a nova funcionalidade, aplique as migrações do módulo no
ambiente apropriado. Após atualizar o módulo, limpe o cache do Akaunting
(`php artisan optimize:clear`) e reinicie os processos PHP e filas que
mantêm OPcache. Valide em ambiente isolado com dados sintéticos, sem emitir
notas reais.
