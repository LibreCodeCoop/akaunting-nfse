<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Matriz de rastreabilidade Web / API / domínio / testes — NFS-e

Esta matriz atende à rastreabilidade exigida pelo épico #497. Lista as capacidades
existentes, não apenas as ferramentas permitidas ao MCP. **Existência da rota
ou de um teste não constitui homologação integrada.** A autoridade fiscal,
permissões e isolamento de empresa pertencem ao Akaunting e aos serviços
do módulo; o MCP não deve reproduzir a regra.

**Padrões de acesso:** nos exemplos abaixo, o sufixo de API NFS-e recebe
`/{company_id}/api/nfse/` conforme os macros do Core e a API de recursos
nativos do Core utiliza suas próprias rotas `/api/...`, com
`company_id` validado pelo Core. A interface usa sessão/CSRF sob
`/{company_id}/nfse/` (ou as telas Core). As duas formas compartilham
o serviço de aplicação, não necessariamente a mesma URL.

| Frente / capacidade | Interface Web | API nativa (método e sufixo) | Serviço / fonte de verdade | Evidência automatizada |
| --- | --- | --- | --- | --- |
| Fundação: descoberta contratual | Consumidores JSON das telas NFS-e | GET `discovery` | `ApiCapabilityController`; metadados declarados nas rotas | `ApiCapabilityDiscoveryTest`; `nfse_native_api.feature` |
| #500: consulta de itens e perfis fiscais | Cadastro Core de itens / seleção de serviço | Core GET/POST/PATCH `items` | `ItemFiscalProfileValidator`; eventos nativos Core | `ItemFiscalProfileTest`; `nfse_native_api.feature`; `nfse-item-validation.spec.ts` |
| #500: cadastro de tomadores | Cadastro Core de contatos | Core GET/POST/PATCH `contacts` | `ContactFiscalProfileStore`; `PersistContactFiscalProfile` | `ContactFiscalProfileRoundTripTest`; `nfse_native_api.feature` |
| #500: serviços oficiais LC116 | Assistente fiscal e seletor de serviços | GET `national-services`, GET `lc116/services` | `NationalServicesController`; catálogo `nfse-php` | `NationalTaxCatalogSearchTest`; `nfse_native_api.feature` |
| #500: unidades federativas e municípios | Cadastro de serviço e tomador | GET `ibge/ufs`, GET `ibge/municipalities/{uf}` | `SettingsController`, catálogo IBGE | `AuxiliaryApiParityTest`; `nfse_native_api.feature` |
| #501: perfil NFS-e por empresa | Configurações / editor de perfil | GET/POST `settings/company-profiles`, PATCH/DELETE `settings/company-profiles/{companyId}` | `NfseCompanyProfileManager`, `NfseCompanyProfileCatalog` | `CompanyProfileLifecycleTest`; `nfse_api_contracts.feature`; `nfse-settings.spec.ts` |
| #501: limpeza fiscal controlada | Configurações / ação de limpeza | POST `settings/company-profiles/{companyId}/cleanup` | `NfseCompanyProfileManager` | `CompanyProfileLifecycleTest`; `nfse-settings.spec.ts` |
| #501: certificado privado | Editor com upload PFX; jamais transferir chave privada em JSON | POST `certificates/inspect`; POST de perfil com multipart | `CertificateController`; `NfseCompanyProfileManager` | `CompanyProfileLifecycleTest`; `nfse-certificate.spec.ts` |
| #501: Vault / OpenBao | Configuração e diagnóstico autenticados | GET/PATCH `settings/vault`, POST `settings/vault/diagnostics` | `SettingsController`; integração com Vault | `GlobalVaultInfrastructureTest`; `nfse-settings.spec.ts` |
| #501: arquivo WebDAV | Formulário de configurações de artefatos | GET/PATCH `settings/artifacts` | `SettingsController`; políticas de armazenamento | `ArtifactSettingsApiTest`; `nfse-artifact-settings.spec.ts` |
| #502: associação fiscal a impostos | Administração de associações existentes | GET/POST `settings/tax-associations`, PATCH `settings/tax-associations/{associationId}` | `TaxFiscalAssociationStore`; `FiscalTaxAssociationDiagnostics` | `TaxAssociationAdminTest`; `nfse-fiscal-tax-api.spec.ts` |
| #502: tributos aproximados | Formulário de fontes fiscais | GET/POST `settings/approximate-taxes` | `SettingsController`; proveniência de fontes | `ApproximateTaxSourceFeatureTest`; `nfse_native_api.feature` |
| #503: dashboard fiscal | Dashboard NFS-e hidratado por JSON | GET `dashboard` | `InvoiceController::dashboardData` | `DashboardApiParityTest`; `nfse_native_api.feature` |
| #503: livro fiscal | Livro e filtros de competência/status | GET `ledger` | `FiscalLedgerCatalog`; `FiscalLedgerApiController` | `FiscalLedgerApiTest`; `nfse-fiscal-ledger-api.spec.ts` |
| #503: XML autorizado e DANFSe | Download autenticado pelo livro fiscal ou fatura | GET `receipts/{receipt}/artifacts/{artifact}` | `FiscalLedgerApiController`; artefato associado ao recibo | `IssuedNfseArtifactStoreTest`; `ScopedDanfseArtifactFlowTest` |
| #503: relatório de fechamento | Tela e exportação de competência | GET `closing`, `closing/export`, `closing/ibs-cbs-evidence` | `CompetenceClosingReport`; `CommercialFiscalReviewReport` | `FiscalClosingApiTest`; `nfse-fiscal-closing-api.spec.ts` |
| #503: tentativas e estados | Painel da fatura e histórico | GET `invoices/{invoice}/emission-attempts`, GET `invoices/{invoice}/post-emission-status` | `EmissionAttemptJournal`; `PostEmissionState` | `EmissionAttemptAccessTest`; `PostEmissionStatusEndpointTest` |
| #504: prévia e fato gerador | Prévia no painel fiscal | GET `invoices/{invoice}/service-preview`, GET `invoices/{invoice}/fiscal-assessment`; POST `invoices/{invoice}/fiscal-event-evidence` | `FinalFiscalAssessmentPreflight`; `FiscalEventEvidenceController` | `FiscalInvoiceEmissionApiTest`; `nfse-fiscal-assessment.spec.ts` |
| #504: decisão por fatura | Decisão explícita do operador | GET/POST `invoices/{invoice}/fiscal-decision` | `InvoiceFiscalDecision`; política de decisão | `InvoiceFiscalDecisionTest`; `nfse-invoice-decision.spec.ts` |
| #504: política de categoria | Cadastro de categoria / regra de emissão | GET/POST `settings/category-issuance` | `CategoryIssuancePolicyController` | `NativeInvoiceFiscalActionsAccessTest`; `nfse-native-category-issuance.spec.ts` |
| #504: autoridade DPS | Configuração / prontidão e avanço deliberado | GET `dps/readiness`, GET `dps/lookup`, POST `dps/advance` | `DpsOperationalReadiness`; `DpsReservationService` | `DpsReadinessApiTest`; `nfse_native_api.feature` |
| #504: emitir | Fatura Core / modal NFS-e | POST `invoices/{invoice}/emit` | `IssueInvoiceNfse`; journal e coordenador DPS | `FiscalInvoiceEmissionApiTest`; `nfse-emission.spec.ts`; `nfse_native_api.feature` |
| #504: atualizar estado | Painel da fatura / ação de atualização | POST `invoices/{invoice}/refresh`, POST `invoices/refresh-all` | `RefreshInvoiceNfse`; `PostEmissionState` | `FiscalInvoiceMutationApiTest`; `nfse-reemit-status.spec.ts` |
| #505: cancelar | Modal de cancelamento de fatura | DELETE `invoices/{invoice}` | `CancelInvoiceNfse`; recibos históricos preservados | `FiscalInvoiceMutationApiTest`; `nfse_native_api.feature` |
| #505: reemitir | Fatura e controle de reemissão | POST `invoices/{invoice}/reemit` | `IssueInvoiceNfse`; identidade DPS | `InvoiceSubstitutionFlowTest`; `nfse-reemit-status.spec.ts` |
| #505: substituir | Fluxo de substituição documentado | POST `invoices/{invoice}/substitute` | `SubstituteInvoiceNfse`; vínculo de recibos | `InvoiceSubstitutionFlowTest`; `nfse-substitution.spec.ts` |
| #506: distribuição ADN | Botão de consulta explícita, POST com CSRF; GET legado apenas para compatibilidade | POST `adn/distribution/consult`; GET legado `adn/distribution` **não é leitura pura** | `AdnController`; `AdnSynchronizationService` | `AdnNativeApiTest`; `nfse_native_api.feature`; `deterministic-harness.spec.ts` |
| #506: revisão recebida | Lista e preview de documentos | GET `adn/review-options`, GET `adn/documents`, GET `adn/documents/{document}/preview`, GET `adn/documents/{document}/review-history` | `AdnAccountingPreview`; classificação de candidatos | `AdnAccountingReviewQueueTest`; `nfse-adn-import.spec.ts` |
| #506: importar/ignorar | Aprovação e importação de rascunho | POST `adn/documents/{document}/import`, POST `adn/documents/{document}/ignore` | `AdnController`; lançamento nativo de compra | `AdnDraftBillImportTest`; `nfse_api_contracts.feature` |
| #506: eventos ADN por fatura | Histórico na fatura | GET `invoices/{invoice}/adn-events` | `AdnController`; recibos e eventos | `AdnCompanyIsolationTest`; `nfse_native_api.feature` |
| #507: planejar lote | Revisão prévia de elegibilidade | POST `bulk/plan` | `BulkEmissionPlanner` | `BulkEmissionApiTest`; `nfse-bulk-api.spec.ts` |
| #507: iniciar lote | Confirmação de envio em fila | POST `bulk/dispatch` | `BulkEmissionDispatcher`; fila e operações idempotentes | `BulkEmissionDispatchControllerTest`; `nfse-bulk-emission.spec.ts` |
| #507: progresso e recuperação | Lista e detalhes de execuções | GET `bulk/runs`, GET `bulk/runs/{run}` | `BulkEmissionRunRecorder` | `BulkEmissionProgressTest`; `nfse-bulk-api.spec.ts` |
| Integração Core: enviar e-mail | Modal de fatura, com confirmação humana | POST `invoices/{invoice}/emails` | `InvoiceEmails`; evento `DocumentSending` pode emitir NFS-e | `NfseEmailDeliveryApiTest`; `nfse_native_api.feature` |
| Integração Core: preferências por cliente | Composição de e-mail | GET/POST `invoices/{invoice}/email-preferences` | `CustomerEmailPreferencesController` | `AuxiliaryApiParityTest`; `nfse_native_api.feature` |
| Integração Core: rodapé | Formulário de configurações | GET/PATCH `settings/email-footer` | `SettingsController` | `AuxiliaryApiParityTest`; `nfse-settings.spec.ts` |
| Integração Core: jobs/listeners | Envio automático com preflight e empresa persistida | Serviço interno; não exige endpoint alternativo | `EmitNfseBeforeDocumentSend`; `AutomaticInvoiceEmissionPreflight` | `DocumentSendingFiscalEmissionTest`; `AutomaticInvoiceFiscalIssuerTest` |

## Fronteiras que continuam intencionais

1. **Consulta não é autorização de emissão.** Prontidão mecânica de DPS,
   valor municipal de cache e prévia fiscal não autorizam novo POST SEFIN.
   Respostas fiscais ambíguas não permitem reenvio automático.
2. **Conteúdo e segredos não são JSON para o modelo.** PFX, senha do PFX,
   credenciais Vault e artefatos binários usam contratos com autorização e
   transporte adequados. XML bruto não integra a resposta da consulta
   operacional ADN na API externa; o navegador autenticado conserva o
   detalhamento histórico.
3. **Operações externas não são GETs puros.** Distribuição ADN e consulta
   municipal podem acessar serviços oficiais ou persistir snapshots. Clientes
   novos devem preferir POST explícito; GETs legados permanecem compatíveis
   e são excluídos do catálogo MCP de leituras seguras.
4. **MCP não é o universo das capacidades Web/API.** A auditoria de rotas
   do MCP mantém separadas execução direta, navegação para a interface e
   operações ainda bloqueadas por segurança. A cobertura do módulo não é
   automaticamente igual à cobertura do MCP.

## Critérios de aceite e evidência faltante

Esta matriz indica **onde cada comportamento é exercitado**, não declara que
todos os cenários descritos em #497 já passaram por homologação final.
As pipelines de PHPUnit/Feature/Behat/Playwright são evidências da versão
específica dos commits. Antes de marcar o épico como concluído ainda é
necessário:

- Validação de integração externa independente com identidade, ACL e empresa
  sintéticas no `akaunting-docker`, incluindo respostas negativas, streaming
  de arquivos, e aprovação explícita de mutações.
- Revisão independente dos PRs #600 e `akaunting-mcp#23`, seguida de
  reavaliação do catálogo após integrar o módulo na branch principal.
- Evidência de que não há caminhos de formulário HTML remanescentes para
  **ações fiscais ativas**, além das telas de apresentação e dos uploads
  de certificado que necessitam multipart autenticado.
- Reconciliação final de execução direta versus handoff protegido, sem
  transformar um redirecionamento em falso sucesso de API.

Referências: `Routes/api.php`, `Routes/admin.php`, `docs/native-api.md`,
`tests/integration/features/nfse_native_api.feature` e
`e2e/deterministic-harness.spec.ts`.
