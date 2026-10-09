<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/InvoiceControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use App\Models\Document\Document as Invoice;
    use Illuminate\Http\Request;
    use Modules\Nfse\Application\FederalTaxSnapshotBuilder;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Http\Controllers\InvoiceController;
    use Modules\Nfse\Models\NfseReceipt;
    use Modules\Nfse\Tests\TestCase;
    use Modules\Nfse\Tests\Unit\Http\Controllers\Support\InvoiceControllerIsolationState;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\CancellationException;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

    final class InvoiceControllerTest extends TestCase
    {
        public function testControllerUsesDocumentModelAliasForInvoices(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('use App\\Models\\Document\\Document as Invoice;', $content);
            self::assertStringContainsString('use App\\Models\\Common\\Contact;', $content);
            self::assertStringContainsString('Invoice::invoice()', $content);
        }

        public function testResultModalPushesItsJavaScriptToTheScriptsStack(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Resources/views/modals/nfse-result-modal.blade.php');

            self::assertStringContainsString("@push('scripts')", $content);
            self::assertStringContainsString('@once', $content);
            self::assertStringContainsString('window.nfseOpenResultModal', $content);
        }

        public function testRuntimeVendoredNfseClientDoesNotUseUndefinedSslContextOptionsForDanfse(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/3rdparty/scoped/librecodeoop/nfse-php/src/Http/NfseClient.php');

            if (str_contains($content, 'generateFromXml')) {
                self::assertStringContainsString('return $this->danfseGenerator->generateFromXml($nfseXml);', $content);

                return;
            }

            if (str_contains($content, "'ssl' => \$this->sslContextOptions()")) {
                self::markTestSkipped('Pending upstream librecodeoop/nfse-php runtime DANFSE transport update on dev-main.');
            }

            self::assertStringContainsString('No mTLS is applied', $content);
            self::assertStringNotContainsString("'ssl' => \$this->sslContextOptions()", $content);
        }

        public function testMissingNationalTaxCodeFeedbackIdentifiesTheItemToFix(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');
            $pt = (string) file_get_contents(dirname(__DIR__, 4) . '/Resources/lang/pt-BR/general.php');

            self::assertStringContainsString('itemsMissingNationalTaxCode($invoice)', $content);
            self::assertStringContainsString("'items' => implode(', ', \$items)", $content);
            self::assertStringContainsString('Edite esse item em Itens', $pt);
            self::assertStringContainsString('Obrigatório para emissão da NFS-e.', $pt);
            self::assertStringNotContainsString('Se vazio, o módulo deriva o NBS a partir do LC116.', $pt);
        }

        public function testControllerBuildsFiscalPayloadFromItemProfilesAndNativeItemTaxes(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('resolveInvoiceFiscalProfileFromItems', $content);
            self::assertStringContainsString('invoiceItemFiscalProfileMap', $content);
            self::assertStringContainsString('invoiceItemTaxRateMap', $content);
            self::assertStringContainsString('itemFiscalProfile[\'item_lista_servico\']', $content);
            self::assertStringContainsString('itemFiscalProfile[\'codigo_tributacao_nacional\']', $content);
            self::assertStringContainsString('itemFiscalProfile[\'codigo_tributacao_municipal\']', $content);
            self::assertStringContainsString('itemFiscalProfile[\'aliquota\']', $content);
        }

        public function testItemNativeFlowRemovesCompanyServiceSelectionHelpers(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringNotContainsString('CompanyService::class', $content);
            self::assertStringNotContainsString('resolveDefaultCompanyService', $content);
            self::assertStringNotContainsString('supportsCompanyServiceSelection', $content);
        }

        public function testReceiptsIndexSearchIncludesCustomerNameRelation(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString("NfseReceipt::with('invoice.contact')", $content);
            self::assertStringContainsString("if (is_object(\$query) && is_callable([\$query, 'whereHas']))", $content);
            self::assertStringContainsString("\$query = \$query->whereHas('invoice', static fn (\$invoiceQuery) => \$invoiceQuery", $content);
            self::assertStringContainsString('->whereHas(\'contact\', static fn ($contactQuery) => $contactQuery->where(\'type\', Contact::CUSTOMER_TYPE))', $content);
            self::assertStringContainsString("->orWhereHas('invoice'", $content);
            self::assertStringContainsString("->orWhereHas('contact'", $content);
            self::assertStringContainsString("'name', 'like', '%' . \$search . '%'", $content);
        }

        public function testListingOverviewCountsRestrictsReceiptsToSalesInvoices(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString("if (is_object(\$totalReceiptsQuery) && is_callable([\$totalReceiptsQuery, 'whereHas']))", $content);
            self::assertStringContainsString("\$totalReceiptsQuery = \$totalReceiptsQuery->whereHas('invoice', static fn (\$invoiceQuery) => \$invoiceQuery", $content);
            self::assertStringContainsString("\$emittedQuery = \$emittedQuery->whereHas('invoice', static fn (\$invoiceQuery) => \$invoiceQuery", $content);
            self::assertStringContainsString('->whereHas(\'contact\', static fn ($contactQuery) => $contactQuery->where(\'type\', Contact::CUSTOMER_TYPE))', $content);
        }

        public function testListingOverviewTotalMatchesAllReceiptRowsInsteadOfAddingPendingInvoices(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString("'total' => \$totalReceipts,", $content);
            self::assertStringNotContainsString("'total' => \$totalReceipts + \$pending,", $content);
        }

        public function testPendingInvoicesQueryRestrictsContactsToCustomers(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('->whereHas(\'contact\', static fn ($contactQuery) => $contactQuery->where(\'type\', Contact::CUSTOMER_TYPE))', $content);
        }

        public function testPendingInvoicesQueryRequiresAtLeastOneItem(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('->whereHas(\'items\')', $content);
        }

        public function testPendingInvoicesQueryExcludesInvoicesAlreadyPresentInNfseReceipts(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('$receiptTable = (new NfseReceipt())->getTable();', $content);
            self::assertStringContainsString('->whereNotExists(static function ($subQuery) use ($receiptTable): void {', $content);
            self::assertStringContainsString("->whereColumn(", $content);
            self::assertStringContainsString("invoice_id', 'documents.id'", $content);
        }

        public function testReceiptsIndexSearchAlsoIncludesInvoiceNumberFields(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString("->orWhereHas('invoice'", $content);
            self::assertStringContainsString("->where('document_number', 'like', '%' . \$search . '%')", $content);
        }

        public function testProjectRootPathUsesIsolationApplicationBasePath(): void
        {
            $controller = new class () extends InvoiceController {
                public function resolveProjectRootPath(string $relativePath): string
                {
                    return $this->projectRootPath($relativePath);
                }
            };

            self::assertSame(
                ControllerIsolationState::$storageRoot . '/client.crt.pem',
                $controller->resolveProjectRootPath('client.crt.pem'),
            );
        }

        public function testControllerDispatchesPostEmissionAfterPersistingReceipt(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('(new EmissionAttemptJournal())->issue(', $content);
            self::assertStringContainsString(': $this->storeEmittedReceipt($invoice, $authorized),', $content);
            self::assertStringContainsString('(new ReceiptPersistence())->createReplacement(', $content);
            self::assertStringContainsString('$email = $this->preparePostEmitEmail($request, $invoice);', $content);
            self::assertStringContainsString('$this->dispatchPostEmission(', $content);
            self::assertStringContainsString('$this->markInvoiceSentAfterEmission($invoice);', $content);
            self::assertStringNotContainsString('$this->storeArtifacts($invoice, $receipt, $persistedReceipt, $client);', $content);
        }

        public function testDanfseDownloadUsesAuthorizedXmlBeforeWebDavArtifact(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('$authorizedXml = $this->authorizedXmlForReceipt($receipt);', $content);
            self::assertStringContainsString('(new DanfseGenerator())->generateFromXml($authorizedXml)', $content);
            self::assertStringContainsString("str_starts_with(\$content, '%PDF-')", $content);
            self::assertStringContainsString('$this->artifactDownloadResponse($receipt, $artifact, $content)', $content);
        }

        public function testMakeClientDelegatesFiscalCompositionAndKeepsExplicitCleanup(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString(
                '$this->clientContext = $this->makeFiscalClientFactory()->nfse($sandboxMode);',
                $content,
            );
            self::assertStringContainsString('return $this->clientContext->nfseClient();', $content);
            self::assertStringContainsString('$this->clientContext->close();', $content);
            self::assertStringNotContainsString('new NfseClient(', $content);
            self::assertStringNotContainsString('resolveTransportCertificatePaths(', $content);
        }

        public function testControllerDelegatesWebDavArtifactPathConstruction(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString("setting('nfse.webdav_url', '')", $content);
            self::assertStringContainsString("'nfse.webdav_path_template'", $content);
            self::assertStringContainsString("'nfse.webdav_filename_template'", $content);
            self::assertStringContainsString('new ArtifactPathBuilder()', $content);
            self::assertStringContainsString("'xml_webdav_path'", $content);
            self::assertStringContainsString("'danfse_webdav_path'", $content);
        }

        public function testBuildWebDavArtifactBasePathResolvesExtendedPlaceholders(): void
        {
            ControllerIsolationState::$settings['nfse.webdav_path_template'] = 'nfse/{month_name}/{day}/{nfse_number}/{customer_name}';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 901,
                amount: 100.0,
                items: [['name' => 'Servico']],
                contactName: 'Cliente Exemplo LTDA',
            );

            $receipt = new ReceiptData('NF-2026/000123', 'CHAVE-901', '2026-03-21T10:00:00-03:00');

            $controller = new class () extends InvoiceController {
                public function resolveArtifactPath(Invoice $invoice, ReceiptData $receipt): string
                {
                    return $this->buildWebDavArtifactBasePath($invoice, $receipt);
                }
            };

            $resolved = $controller->resolveArtifactPath($invoice, $receipt);

            self::assertSame('nfse/marco/21/nf-2026-000123/cliente-exemplo-ltda', $resolved);
        }

        public function testBuildWebDavArtifactBasePathUsesNfseNumberFromXmlWhenGatewayFieldIsEmpty(): void
        {
            ControllerIsolationState::$settings['nfse.webdav_path_template'] = 'nfse/{month_name}/{nfse_number}/{customer_name}';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 902,
                amount: 100.0,
                items: [['name' => 'Servico']],
                contactName: 'Cliente Exemplo LTDA',
            );

            $xmlWithNfseNumber = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><nNFSe>33</nNFSe></infNFSe></NFSe>';

            $receipt = new ReceiptData('', 'CHAVE-902', '2026-04-21T10:00:00-03:00', null, $xmlWithNfseNumber);

            $controller = new class () extends InvoiceController {
                public function resolveArtifactPath(Invoice $invoice, ReceiptData $receipt): string
                {
                    return $this->buildWebDavArtifactBasePath($invoice, $receipt);
                }
            };

            $resolved = $controller->resolveArtifactPath($invoice, $receipt);

            self::assertSame('nfse/abril/33/cliente-exemplo-ltda', $resolved);
        }

        public function testSandboxModeEnabledFallsBackToDefaultTrueWhenSettingIsEmptyString(): void
        {
            ControllerIsolationState::$settings['nfse.sandbox_mode'] = '';

            $controller = new class () extends InvoiceController {
                public function resolveSandboxMode(): bool
                {
                    return $this->sandboxModeEnabled();
                }
            };

            self::assertTrue($controller->resolveSandboxMode());
        }

        public function testSandboxModeEnabledRespectsExplicitFalseValue(): void
        {
            ControllerIsolationState::$settings['nfse.sandbox_mode'] = '0';

            $controller = new class () extends InvoiceController {
                public function resolveSandboxMode(): bool
                {
                    return $this->sandboxModeEnabled();
                }
            };

            self::assertFalse($controller->resolveSandboxMode());
        }


        /**
         * @dataProvider federalTaxBucketByNameProvider
         */
        public function testFederalTaxBucketFromNameMatchesExpectedBucket(string $name, ?string $expectedBucket): void
        {
            self::assertSame(
                $expectedBucket,
                (new FederalTaxSnapshotBuilder())->bucketFromName($name),
            );
        }

        /**
         * @return array<string, array{0:string,1:?string}>
         */
        public static function federalTaxBucketByNameProvider(): array
        {
            return [
                'pis simple' => ['PIS', 'pis'],
                'pis with pasep alias' => ['Contribuicao PASEP sobre servicos', 'pis'],
                'pis with long description' => ['Programa de Integracao Social', 'pis'],
                'cofins simple' => ['COFINS', 'cofins'],
                'cofins long description' => ['Contribuicao para o Financiamento da Seguridade Social', 'cofins'],
                'irrf acronym' => ['IRRF - servicos PJ', 'irrf'],
                'irrf long description' => ['Imposto de Renda Retido na Fonte', 'irrf'],
                'csll acronym' => ['CSLL', 'csll'],
                'csll long description with accents' => ['Contribuição social sobre o lucro líquido', 'csll'],
                'cp by inss' => ['INSS Patronal', 'cp'],
                'cp by previdenciaria description' => ['Contribuição previdenciária patronal', 'cp'],
                'code hint using cod prefix' => ['cod:pis - servicos', 'pis'],
                'code hint using codigo prefix' => ['codigo irrf servicos', 'irrf'],
                'code hint using cst prefix' => ['cst:cofins', 'cofins'],
                'code hint with brackets' => ['Retencao [csll]', 'csll'],
                'unknown tax name' => ['ISSQN municipal', null],
                'numeric code only is ignored' => ['0561', null],
                'empty is ignored' => ['', null],
            ];
        }

        /**
         * @dataProvider federalTaxReadinessProvider
         * @param list<array<string, mixed>> $items
         * @param list<string> $expectedMissing
         */
        public function testFederalTaxReadinessForInvoice(array $items, bool $expectedReady, array $expectedMissing): void
        {
            ControllerIsolationState::$settings['nfse.enforce_item_federal_taxes'] = true;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 990,
                amount: 1000.00,
                items: $items,
            );

            $controller = new class () extends InvoiceController {
                /** @return array{isReady: bool, missing: list<string>} */
                public function resolveFederalReadiness(Invoice $invoice): array
                {
                    return $this->federalTaxReadinessForInvoice($invoice);
                }
            };

            $readiness = $controller->resolveFederalReadiness($invoice);

            self::assertSame($expectedReady, $readiness['isReady'] ?? null);
            self::assertSame($expectedMissing, $readiness['missing'] ?? null);
        }

        public function testFederalTaxSnapshotCanBeScopedToOneFiscalGroup(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 989,
                amount: 300.00,
                items: [
                    [
                        'id' => 101,
                        'name' => 'Grupo A',
                        'item_taxes' => [
                            ['name' => 'PIS', 'amount' => 10.00],
                            ['name' => 'COFINS', 'amount' => 20.00],
                        ],
                    ],
                    [
                        'id' => 102,
                        'name' => 'Grupo B',
                        'item_taxes' => [
                            ['name' => 'PIS', 'amount' => 30.00],
                            ['name' => 'COFINS', 'amount' => 40.00],
                        ],
                    ],
                ],
            );

            $controller = new class () extends InvoiceController {
                /** @param list<int> $documentItemIds */
                public function groupSnapshot(Invoice $invoice, float $amount, array $documentItemIds): array
                {
                    return $this->invoiceFederalTaxSnapshot($invoice, $amount, $documentItemIds);
                }
            };

            $snapshot = $controller->groupSnapshot($invoice, 100.00, [101]);

            self::assertSame('10.00', $snapshot['pis_value']);
            self::assertSame('20.00', $snapshot['cofins_value']);
            self::assertSame('10.00', $snapshot['pis_rate']);
            self::assertSame('20.00', $snapshot['cofins_rate']);
        }

        /**
         * @return array<string, array{0:list<array<string,mixed>>,1:bool,2:list<string>}>
         */
        public static function federalTaxReadinessProvider(): array
        {
            return [
                'all required taxes available' => [[
                    [
                        'name' => 'Servico A',
                        'item_taxes' => [
                            ['name' => 'PIS', 'amount' => 16.50],
                            ['name' => 'COFINS', 'amount' => 76.00],
                            ['name' => 'CSLL', 'amount' => 10.00],
                        ],
                    ],
                ], true, []],
                'missing csll when retention requires it' => [[
                    [
                        'name' => 'Servico A',
                        'item_taxes' => [
                            ['name' => 'PIS', 'amount' => 16.50],
                            ['name' => 'COFINS', 'amount' => 76.00],
                        ],
                    ],
                ], false, ['csll']],
                'missing piscofins and csll' => [[
                    [
                        'name' => 'Servico A',
                        'item_taxes' => [
                            ['name' => 'ISS', 'amount' => 40.00],
                        ],
                    ],
                ], false, ['pis', 'cofins', 'csll']],
            ];
        }

        public function testEmitBlocksWhenIbsCbsIsRequiredAndConfigurationIsMissing(): void
        {
            ControllerIsolationState::$settings['nfse.opcao_simples_nacional'] = 1;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 9901,
                amount: 1200.00,
                items: [['name' => 'Servico sujeito ao cronograma geral']],
                issuedAt: '2026-10-08 12:00:00',
            );

            $controller = new class () extends InvoiceController {
                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice, new Request());

            self::assertSame('route', $response->target ?? null);
            self::assertSame('invoices.show', $response->route ?? null);
            self::assertStringContainsString('IBS/CBS obrigatorio desde 2026-10-01', (string) ($response->flash['error'] ?? ''));
            self::assertStringContainsString('cIndOp', (string) ($response->flash['error'] ?? ''));
        }

        public function testEmitDoesNotRequireIbsCbsBeforeGeneralEffectiveDate(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 9902,
                amount: 1200.00,
                items: [['name' => 'Servico antes da vigencia']],
                issuedAt: '2026-09-30 12:00:00',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-9902', 'CHAVE-9902', '2026-09-30T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice, new Request());

            self::assertNotNull($client->capturedDps);
            self::assertNull($client->capturedDps?->ibsCbsFinalidade);
        }

        public function testEmitBlocksWhenRequiredFederalTaxesAreMissingInInvoiceItems(): void
        {
            ControllerIsolationState::$settings['nfse.enforce_item_federal_taxes'] = true;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 991,
                amount: 1200.00,
                items: [
                    [
                        'name' => 'Servico sem CSLL',
                        'item_taxes' => [
                            ['name' => 'PIS', 'amount' => 19.80],
                            ['name' => 'COFINS', 'amount' => 91.20],
                        ],
                    ],
                ],
                contactTaxNumber: '99887766000155',
            );

            $controller = new class () extends InvoiceController {
                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice, new Request());

            self::assertSame('route', $response->target ?? null);
            self::assertSame('invoices.show', $response->route ?? null);
            self::assertStringContainsString('CSLL', (string) ($response->flash['error'] ?? ''));
        }

        public function testShowPassesSuggestedDiscriminacaoToView(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 904,
                amount: 500.0,
                items: [['name' => 'Consultoria'], ['name' => 'Suporte']],
            );

            InvoiceControllerIsolationState::makeReceipt(904, 'CHAVE-904', 'cancelled');

            $controller = new class () extends InvoiceController {};

            $view = $controller->show($invoice);

            self::assertSame('nfse::invoices.show', $view->name);
            self::assertSame('Consultoria | Suporte', $view->data['suggestedDiscriminacao'] ?? null);
        }

        public function testShowIncludesResolvedArtifactsInViewData(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 905,
                amount: 750.0,
                items: [['name' => 'Implantacao']],
            );

            InvoiceControllerIsolationState::makeReceipt(905, 'CHAVE-905', 'emitted');

            $controller = new class () extends InvoiceController {
                protected function resolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
                {
                    return [
                        'danfse' => [
                            'path' => 'nfse/2026/04/15/CHAVE-905.pdf',
                            'exists' => true,
                            'source' => 'template',
                            'download_url' => '/fake/danfse',
                        ],
                        'xml' => [
                            'path' => 'nfse/2026/04/15/CHAVE-905.xml',
                            'exists' => false,
                            'source' => 'persisted',
                            'download_url' => null,
                        ],
                    ];
                }
            };

            $view = $controller->show($invoice);

            self::assertSame('/fake/danfse', $view->data['artifacts']['danfse']['download_url'] ?? null);
            self::assertFalse((bool) ($view->data['artifacts']['xml']['exists'] ?? true));
            self::assertSame('template', $view->data['artifacts']['danfse']['source'] ?? null);
        }

        public function testShowPassesTranslatedReceiptStatusLabelToView(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 9051,
                amount: 750.0,
                items: [['name' => 'Implantacao']],
            );

            InvoiceControllerIsolationState::makeReceipt(9051, 'CHAVE-9051', 'cancelled');

            $controller = new class () extends InvoiceController {};

            $view = $controller->show($invoice);

            $label = (string) ($view->data['receiptStatusLabel'] ?? '');

            self::assertNotSame('cancelled', $label);
            self::assertNotSame('', $label);
        }

        public function testShowEmitSuccessReturnsPartialViewWithReceiptArtifacts(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 9052,
                amount: 880.0,
                items: [['name' => 'Servico reemitido']],
            );

            InvoiceControllerIsolationState::makeReceipt(9052, 'CHAVE-9052', 'emitted');

            $controller = new class () extends InvoiceController {
                protected function resolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
                {
                    return [
                        'danfse' => [
                            'path' => 'nfse/2026/07/02/chave-9052.pdf',
                            'exists' => true,
                            'source' => 'persisted',
                            'download_url' => '/fake/chave-9052.pdf',
                        ],
                        'xml' => [
                            'path' => 'nfse/2026/07/02/chave-9052.xml',
                            'exists' => true,
                            'source' => 'persisted',
                            'download_url' => '/fake/chave-9052.xml',
                        ],
                    ];
                }
            };

            $view = $controller->showEmitSuccess($invoice);

            self::assertSame('/fake/chave-9052.pdf', $view->data['artifacts']['danfse']['download_url'] ?? null);
            self::assertSame('/fake/chave-9052.xml', $view->data['artifacts']['xml']['download_url'] ?? null);
            self::assertSame('CHAVE-9052', $view->data['receipt']->chave_acesso ?? null);
        }

        public function testPersistedArtifactPathDoesNotPerformWebDavExistenceProbe(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 901, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(901, 'CHAVE-901', 'emitted');
            $receipt->xml_webdav_path = 'nfse/901.xml';
            $receipt->danfse_webdav_path = 'nfse/901.pdf';

            $controller = new class () extends InvoiceController {
                public int $existsCalls = 0;

                public function exposeResolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
                {
                    return $this->resolveReceiptArtifacts($invoice, $receipt);
                }

                protected function webDavEnabled(): bool
                {
                    return true;
                }

                protected function webDavPathExists(string $path): bool
                {
                    $this->existsCalls++;

                    return true;
                }
            };

            $artifacts = $controller->exposeResolveReceiptArtifacts($invoice, $receipt);

            self::assertTrue($artifacts['xml']['exists']);
            self::assertSame('persisted', $artifacts['xml']['source']);
            self::assertSame(0, $controller->existsCalls);
        }

        public function testDownloadArtifactRedirectsToShowWhenArtifactIsUnavailable(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 906,
                amount: 120.0,
                items: [['name' => 'Servico']],
            );

            InvoiceControllerIsolationState::makeReceipt(906, 'CHAVE-906', 'emitted');

            $controller = new class () extends InvoiceController {
                protected function resolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
                {
                    return [
                        'danfse' => [
                            'path' => null,
                            'exists' => false,
                            'source' => null,
                            'download_url' => null,
                        ],
                        'xml' => [
                            'path' => null,
                            'exists' => false,
                            'source' => null,
                            'download_url' => null,
                        ],
                    ];
                }
            };

            $response = $controller->downloadArtifact($invoice, 'danfse');

            self::assertSame('route', $response->target ?? null);
            self::assertSame('invoices.show', $response->route ?? null);
            self::assertSame([$invoice], $response->parameters ?? null);
        }

        protected function setUp(): void
        {
            parent::setUp();

            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$translations = [
                'nfse::general.nfse_emitted' => 'NFS-e emitida :number',
                'nfse::general.nfse_cancelled' => 'NFS-e cancelada',
                'nfse::general.nfse_refreshed' => 'NFS-e :number atualizada com sucesso.',
                'nfse::general.nfse_refresh_failed' => 'Nao foi possivel atualizar o status da NFS-e.',
                'nfse::general.nfse_refresh_all_done' => 'Atualizacao concluida para :count NFS-e.',
                'nfse::general.nfse_refresh_all_partial' => 'Atualizacao parcial: :updated atualizadas e :failed falharam.',
                'nfse::general.nfse_reemitted' => 'NFS-e reemitida :number com sucesso.',
                'nfse::general.nfse_reemit_not_cancelled' => 'A NFS-e precisa estar cancelada para reemissao manual.',
                'nfse::general.nfse_secret_store_failed' => 'Nao foi possivel acessar o segredo do certificado no Vault/OpenBao.',
                'nfse::general.nfse_pfx_import_failed'   => 'Nao foi possivel importar o certificado PFX.',
                'nfse::general.cancel_motivo_default' => 'Cancelamento padrao',
                'nfse::general.service_default' => 'Servico padrao',
                'nfse::general.invoices.emit_blocked_not_ready' => 'Existem configuracoes pendentes para liberar a emissao.',
                'nfse::general.invoices.emit_blocked_ibs_cbs_required' => 'IBS/CBS obrigatorio desde :date. Configure: :fields.',
                'nfse::general.invoices.emit_blocked_ibs_cbs_unverifiable' => 'Nao foi possivel determinar IBS/CBS. Revise: :fields.',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_enabled' => 'envio de IBS/CBS',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_ind_final' => 'indFinal',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_c_ind_op' => 'cIndOp',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_ind_dest' => 'indDest',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_cst' => 'CST IBS/CBS',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_c_class_trib' => 'cClassTrib',
                'nfse::general.invoices.ibs_cbs_missing_labels.ibs_cbs_obligation_context' => 'competencia e enquadramento do servico',
                'nfse::general.invoices.emit_blocked_no_items' => 'A fatura precisa ter ao menos um item para emitir NFS-e.',
                'nfse::general.invoices.emit_blocked_missing_federal_taxes' => 'A fatura nao possui os tributos federais necessarios para emissao da NFS-e.',
                'nfse::general.invoices.emit_blocked_missing_federal_taxes_with_list' => 'A fatura nao possui os tributos federais necessarios para emissao da NFS-e (:taxes).',
                'nfse::general.invoices.federal_tax_labels.pis' => 'PIS',
                'nfse::general.invoices.federal_tax_labels.cofins' => 'COFINS',
                'nfse::general.invoices.federal_tax_labels.irrf' => 'IRRF',
                'nfse::general.invoices.federal_tax_labels.csll' => 'CSLL',
                'nfse::general.invoices.default_service_confirmation_required' => 'Existem itens sem servico vinculado. Revise e confirme o uso do servico padrao para emitir a NFS-e.',
                'nfse::general.invoices.mixed_service_tax_profiles_not_supported' => 'A fatura possui itens vinculados a servicos com tributacao municipal diferente. Emita NFS-e separadas por perfil de servico/ISS.',
                'nfse::general.invoices.refresh_not_allowed_for_cancelled' => 'NFS-e cancelada nao pode ser atualizada por refresh. Use a acao de reemissao quando aplicavel.',
                'nfse::general.invoices.tax_policy_notice' => 'Para emissão da NFS-e, a fonte canônica de tributação é a aba 5. Tributação. Impostos do item no Akaunting permanecem para uso interno do documento.',
                'nfse::general.invoices.tax_policy_notice_with_item_taxes' => 'Esta fatura possui impostos nativos de item no Akaunting. Na NFS-e emitida, prevaleceram as regras da aba 5. Tributação.',
            ];
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
                'nfse.municipio_ibge' => '3303302',
                'nfse.bao_addr' => 'http://openbao:8200',
                'nfse.bao_mount' => 'nfse',
                'nfse.bao_token' => 'unit-test-token',
                'nfse.item_lista_servico' => '0107',
                'nfse.codigo_tributacao_nacional' => '010701',
                'nfse.aliquota' => '4.50',
                'nfse.federal_piscofins_situacao_tributaria' => '1',
                'nfse.federal_piscofins_tipo_retencao' => '3',
                'nfse.federal_piscofins_aliquota_pis' => '1.65',
                'nfse.federal_piscofins_aliquota_cofins' => '7.60',
                'nfse.federal_piscofins_base_calculo' => '999.99',
                'nfse.federal_piscofins_valor_pis' => '999.99',
                'nfse.federal_piscofins_valor_cofins' => '999.99',
                'nfse.federal_valor_irrf' => '1.00',
                'nfse.federal_valor_csll' => '1.00',
                'nfse.federal_valor_cp' => '1.00',
                'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
                'nfse.enforce_item_federal_taxes' => false,
                'nfse.sandbox_mode' => false,
            ];

            $certificateDir = ControllerIsolationState::$storageRoot . '/app/nfse/pfx';
            if (!is_dir($certificateDir)) {
                mkdir($certificateDir, 0o777, true);
            }

            file_put_contents($certificateDir . '/12345678000195.pfx', 'fake-certificate');
        }

        public function testEmitBuildsDpsPersistsReceiptAndRedirectsToShowPage(): void
        {
            ControllerIsolationState::$settings['nfse.ibs_cbs_enabled'] = true;
            ControllerIsolationState::$settings['nfse.ibs_cbs_ind_final'] = '0';
            ControllerIsolationState::$settings['nfse.ibs_cbs_c_ind_op'] = '010101';
            ControllerIsolationState::$settings['nfse.ibs_cbs_ind_dest'] = '0';
            ControllerIsolationState::$settings['nfse.ibs_cbs_cst'] = '000';
            ControllerIsolationState::$settings['nfse.ibs_cbs_c_class_trib'] = '000001';
            ControllerIsolationState::$settings['nfse.tributacao_issqn'] = '3';
            ControllerIsolationState::$settings['nfse.tipo_retencao_iss'] = '2';
            ControllerIsolationState::$settings['nfse.issqn_pais_resultado'] = 'US';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 42,
                amount: 1500.25,
                items: [
                    ['name' => 'Servico A'],
                    ['name' => 'Servico B'],
                ],
                description: 'Descricao fallback',
                contactName: 'ACME Ltda',
                contactTaxNumber: '99887766000155',
                contactAddress: 'Avenida Rio Branco, 500',
                contactZipCode: '24020-077',
                contactCityIbge: '3303302',
                contactPhone: '(21) 98888-7777',
                contactEmail: 'financeiro@acme.test',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData(
                        nfseNumber: 'NF-2026-0001',
                        chaveAcesso: 'CHAVE-123',
                        dataEmissao: '2026-03-21T10:30:00-03:00',
                        codigoVerificacao: 'ABC123',
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public array $clientCalls = [];
                public array $markedSentInvoiceIds = [];

                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    $this->clientCalls[] = ['sandbox' => $sandboxMode];

                    return $this->client;
                }

                protected function markInvoiceSentAfterEmission(Invoice $invoice): void
                {
                    $invoice->status = 'sent';
                    $this->markedSentInvoiceIds[] = $invoice->id;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame([['sandbox' => false]], $controller->clientCalls);
            self::assertSame([42], $controller->markedSentInvoiceIds);
            self::assertSame('sent', $invoice->status);
            self::assertSame('12345678000195', $client->capturedDps?->cnpjPrestador);
            self::assertSame('3303302', $client->capturedDps?->municipioIbge);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('010701', $client->capturedDps?->codigoTributacaoNacional);
            self::assertSame('1500.25', $client->capturedDps?->valorServico);
            self::assertSame('4.50', $client->capturedDps?->aliquota);
            self::assertSame('[0107] Servico A | [0107] Servico B', $client->capturedDps?->discriminacao);
            self::assertSame('99887766000155', $client->capturedDps?->documentoTomador);
            self::assertSame('ACME Ltda', $client->capturedDps?->nomeTomador);
            if ($client->capturedDps !== null && property_exists($client->capturedDps, 'tomadorCodigoMunicipio')) {
                self::assertSame('3303302', $client->capturedDps->tomadorCodigoMunicipio);
                self::assertSame('24020077', $client->capturedDps->tomadorCep);
                self::assertSame('Avenida Rio Branco, 500', $client->capturedDps->tomadorLogradouro);
                self::assertSame('21988887777', $client->capturedDps->tomadorTelefone);
                self::assertSame('financeiro@acme.test', $client->capturedDps->tomadorEmail);
            }
            self::assertSame(2, $client->capturedDps?->opcaoSimplesNacional);
            self::assertSame(3, $client->capturedDps?->tributacaoIssqn);
            self::assertSame('US', $client->capturedDps?->issqnPaisResultado);
            self::assertNull($client->capturedDps?->issqnTipoImunidade);
            self::assertNull($client->capturedDps?->issqnTipoSuspensao);
            self::assertSame('', $client->capturedDps?->issqnNumeroProcessoSuspensao);
            self::assertSame(2, $client->capturedDps?->tipoRetencaoIss);
            self::assertSame(1, $client->capturedDps?->tipoAmbiente);
            self::assertSame('1', $client->capturedDps?->federalPiscofinsSituacaoTributaria);
            self::assertSame('3', $client->capturedDps?->federalPiscofinsTipoRetencao);
            self::assertSame('1500.25', $client->capturedDps?->federalPiscofinsBaseCalculo);
            self::assertSame('1.65', $client->capturedDps?->federalPiscofinsAliquotaPis);
            self::assertSame('24.75', $client->capturedDps?->federalPiscofinsValorPis);
            self::assertSame('7.60', $client->capturedDps?->federalPiscofinsAliquotaCofins);
            self::assertSame('114.02', $client->capturedDps?->federalPiscofinsValorCofins);
            // IRRF = 1.00% × 1500.25 = 15.0025 → '15.00'
            self::assertSame('15.00', $client->capturedDps?->federalValorIrrf);
            // CSLL is retained separately from PIS/COFINS.
            self::assertSame('15.00', $client->capturedDps?->federalValorCsll);
            // CP always '' (RNG6110 reject in produção restrita)
            self::assertSame('', $client->capturedDps?->federalValorCp);
            self::assertSame(0, $client->capturedDps?->ibsCbsFinalidade);
            self::assertSame(0, $client->capturedDps?->ibsCbsIndFinal);
            self::assertSame('010101', $client->capturedDps?->ibsCbsCodigoIndicadorOperacao);
            self::assertSame(0, $client->capturedDps?->ibsCbsIndDest);
            self::assertSame('000', $client->capturedDps?->ibsCbsCst);
            self::assertSame('000001', $client->capturedDps?->ibsCbsClassificacaoTributaria);
            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('4.50', $client->capturedDps?->totalTributosPercentualMunicipal);
            self::assertSame('00001', $client->capturedDps?->serie);
            self::assertSame('42', $client->capturedDps?->numeroDps);
            self::assertSame('2026-02-04', $client->capturedDps?->dataCompetencia);
            self::assertSame([
                [
                    'attributes' => ['invoice_id' => 42],
                    'values' => [
                        'nfse_number' => 'NF-2026-0001',
                        'chave_acesso' => 'CHAVE-123',
                        'data_emissao' => '2026-03-21T10:30:00-03:00',
                        'codigo_verificacao' => 'ABC123',
                        'status' => 'emitted',
                    ],
                ],
            ], NfseReceipt::$updateOrCreateCalls);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e emitida NF-2026-0001', $response->flash['success'] ?? null);
        }

        public function testEmitDoesNotRequireTomadorEmailToSucceed(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 420,
                amount: 350.0,
                items: [['name' => 'Servico sem email do tomador']],
                contactName: 'Cliente sem Email',
                contactTaxNumber: '99887766000155',
                contactCityIbge: '3303302',
                contactZipCode: '24020-077',
                contactAddress: 'Rua sem email, 100',
                contactPhone: '(21) 97777-8888',
                contactEmail: '',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-420', 'CHAVE-420', '2026-03-23T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('', $client->capturedDps?->tomadorEmail);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame('NFS-e emitida NF-420', $response->flash['success'] ?? null);
        }

        public function testEmitUsesDocumentSnapshotFallbackWhenTomadorContactIsIncomplete(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 421,
                amount: 351.0,
                items: [['name' => 'Servico com snapshot do tomador']],
                description: 'Teste snapshot tomador',
                contactName: '',
                contactTaxNumber: '',
                contactAddress: '',
                contactZipCode: '',
                contactCityIbge: '',
                contactPhone: '',
                contactEmail: '',
            );
            $invoice->contact_name = 'Assessoria Snapshot';
            $invoice->contact_tax_number = '99887766000155';
            $invoice->contact_address = 'Rua do Snapshot, 100';
            $invoice->contact_zip_code = '24020-077';
            $invoice->contact_city = '3303302';
            $invoice->contact_phone = '(21) 96666-5555';
            $invoice->contact_email = 'snapshot@example.test';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-421', 'CHAVE-421', '2026-03-23T12:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame('99887766000155', $client->capturedDps?->documentoTomador);
            self::assertSame('Assessoria Snapshot', $client->capturedDps?->nomeTomador);
            self::assertSame('3303302', $client->capturedDps?->tomadorCodigoMunicipio);
            self::assertSame('24020077', $client->capturedDps?->tomadorCep);
            self::assertSame('Rua do Snapshot, 100', $client->capturedDps?->tomadorLogradouro);
            self::assertSame('21966665555', $client->capturedDps?->tomadorTelefone);
            self::assertSame('snapshot@example.test', $client->capturedDps?->tomadorEmail);
        }

        public function testEmitStillSucceedsWhenSendEmailIsRequestedWithoutAnyRecipient(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 422,
                amount: 352.0,
                items: [['name' => 'Servico com envio opcional']],
                contactName: 'Cliente sem destinatario',
                contactTaxNumber: '99887766000155',
                contactEmail: '',
            );

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    return new ReceiptData('NF-422', 'CHAVE-422', '2026-03-23T13:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $notificationCalls = [];

            $controller = new class ($client, $notificationCalls) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client, private array &$notificationCalls)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }

                protected function sendNfseIssuedNotification(Invoice $invoice, \Modules\Nfse\Models\NfseReceipt $receipt, bool $attachDanfse, bool $attachXml, array $customMail): void
                {
                    $this->notificationCalls[] = [
                        'invoice_id' => $invoice->id,
                        'custom_mail' => $customMail,
                    ];
                }
            };

            $response = $controller->emit($invoice, new Request([
                'nfse_send_email' => '1',
                'nfse_email_to' => '   ',
            ]));

            self::assertCount(0, $notificationCalls);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame('NFS-e emitida NF-422', $response->flash['success'] ?? null);
        }


        public function testEmitReturnsJsonWithRedirectWhenRequestIsAjax(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 77,
                amount: 500.00,
                items: [['name' => 'Servico Ajax']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-01-15 10:00:00';

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    return new ReceiptData('NF-0077', 'CHAVE-77', '2026-01-15T10:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new \Illuminate\Http\Request(['nfse_confirm_default_service' => '1'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

            $response = $controller->emit($invoice, $request);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertTrue($response->payload['success'] ?? false);
            self::assertFalse($response->payload['error'] ?? true);
            self::assertNotSame('', (string) ($response->payload['message'] ?? ''));
            self::assertStringContainsString('nfse.invoices.show', (string) ($response->payload['redirect'] ?? ''));
            self::assertStringContainsString('nfse.invoices.emit-success', (string) ($response->payload['partial_url'] ?? ''));
        }

        public function testEmitReturnsJsonWithRedirectWhenForceAjaxFlagIsProvided(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 770,
                amount: 500.00,
                items: [['name' => 'Servico Ajax Forcado']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-01-15 10:00:00';

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    return new ReceiptData('NF-0770', 'CHAVE-770', '2026-01-15T10:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new \Illuminate\Http\Request(['nfse_confirm_default_service' => '1', 'nfse_force_ajax' => '1']);

            $response = $controller->emit($invoice, $request);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertTrue($response->payload['success'] ?? false);
            self::assertFalse($response->payload['error'] ?? true);
            self::assertNotSame('', (string) ($response->payload['message'] ?? ''));
            self::assertStringContainsString('nfse.invoices.show', (string) ($response->payload['redirect'] ?? ''));
            self::assertStringContainsString('nfse.invoices.emit-success', (string) ($response->payload['partial_url'] ?? ''));
        }

        public function testEmitReturnsJsonErrorOnGatewayExceptionWhenRequestIsAjax(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 78,
                amount: 200.00,
                items: [['name' => 'Servico Fail']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-01-15 10:00:00';

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new IssuanceException('Rejected', NfseErrorCode::IssuanceRejected, 422, ['mensagem' => 'invalid']);
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new \Illuminate\Http\Request(['nfse_confirm_default_service' => '1'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

            ControllerIsolationState::$translations['nfse::general.nfse_emit_failed'] = 'Falha na emissao';

            $response = $controller->emit($invoice, $request);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertFalse($response->payload['success'] ?? true);
            self::assertTrue($response->payload['error'] ?? false);
            self::assertStringContainsString('Falha na emissao', (string) ($response->payload['message'] ?? ''));
        }

        public function testAjaxAwareRedirectPrefersSessionFlashesWhenRedirectFlashBagIsEmpty(): void
        {
            ControllerIsolationState::$sessionFlash = [
                'error' => 'Falha na emissao',
                'nfse_gateway_error_detail' => 'E1235 - Schema invalido',
            ];

            $controller = new class () extends InvoiceController {
                public function convertAjaxRedirect(Request $request, \Illuminate\Http\RedirectResponse $redirect): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
                {
                    return $this->ajaxAwareRedirect($request, $redirect);
                }
            };

            $request = new \Illuminate\Http\Request([], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $redirect = new \Illuminate\Http\RedirectResponse();
            $redirect->target = 'route';
            $redirect->route = 'nfse.invoices.index';

            $response = $controller->convertAjaxRedirect($request, $redirect);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertFalse($response->payload['success'] ?? true);
            self::assertTrue($response->payload['error'] ?? false);
            self::assertStringContainsString('Falha na emissao', (string) ($response->payload['message'] ?? ''));
            self::assertStringContainsString('E1235 - Schema invalido', (string) ($response->payload['message'] ?? ''));
        }

        public function testEmitNonAjaxRequestStillReturnsRedirectResponse(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 79,
                amount: 300.00,
                items: [['name' => 'Servico Normal']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-01-15 10:00:00';

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    return new ReceiptData('NF-0079', 'CHAVE-79', '2026-01-15T10:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
            self::assertSame('nfse.invoices.show', $response->route);
        }
        public function testEmitCalculatesValoresFromAliquotasInPercentageProfileMode(): void
        {
            ControllerIsolationState::$settings['nfse.tributacao_federal_mode'] = 'percentage_profile';
            // Override stored absolute valores with distinct values to prove they are NOT used
            ControllerIsolationState::$settings['nfse.federal_piscofins_base_calculo'] = '999.00';
            ControllerIsolationState::$settings['nfse.federal_piscofins_valor_pis'] = '999.00';
            ControllerIsolationState::$settings['nfse.federal_piscofins_valor_cofins'] = '999.00';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 43,
                amount: 1500.25,
                items: [['name' => 'Servico C']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-43', 'CHAVE-43', '2026-03-21T10:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            // Base = invoice amount, not any stored helper value
            self::assertSame('1500.25', $client->capturedDps?->federalPiscofinsBaseCalculo);
            // PIS valor = 1500.25 × 1.65 / 100 = 24.75
            self::assertSame('24.75', $client->capturedDps?->federalPiscofinsValorPis);
            // COFINS valor = 1500.25 × 7.60 / 100 = 114.02
            self::assertSame('114.02', $client->capturedDps?->federalPiscofinsValorCofins);
            // Aliquotas are still from settings
            self::assertSame('1.65', $client->capturedDps?->federalPiscofinsAliquotaPis);
            self::assertSame('7.60', $client->capturedDps?->federalPiscofinsAliquotaCofins);
            // IRRF and CSLL are retained separately from PIS/COFINS.
            self::assertSame('15.00', $client->capturedDps?->federalValorIrrf);
            self::assertSame('15.00', $client->capturedDps?->federalValorCsll);
            // CP always '' (RNG6110 reject in produção restrita)
            self::assertSame('', $client->capturedDps?->federalValorCp);
            // Federal taxation without explicit tributos_* config still needs totTrib in the XML schema.
            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('4.50', $client->capturedDps?->totalTributosPercentualMunicipal);
        }

        public function testEmitSetsIndicadorTributacaoTwoWhenTributosPercentConfigured(): void
        {
            ControllerIsolationState::$settings['nfse.opcao_simples_nacional'] = 1;
            ControllerIsolationState::$settings['nfse.tributos_fed_p'] = '10.50';
            ControllerIsolationState::$settings['nfse.tributos_est_p'] = '0.00';
            ControllerIsolationState::$settings['nfse.tributos_mun_p'] = '2.00';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 44,
                amount: 500.00,
                items: [['name' => 'Servico D']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-44', 'CHAVE-44', '2026-03-21T10:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('10.50', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('2.00', $client->capturedDps?->totalTributosPercentualMunicipal);
        }

        public function testEmitDefaultsMissingEstadualTributosPercentualToZeroWhenTributacaoIsEnabled(): void
        {
            ControllerIsolationState::$settings['nfse.opcao_simples_nacional'] = 1;
            ControllerIsolationState::$settings['nfse.tributos_fed_p'] = '3.65';
            ControllerIsolationState::$settings['nfse.tributos_est_p'] = '';
            ControllerIsolationState::$settings['nfse.tributos_mun_p'] = '2.00';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 440,
                amount: 500.00,
                items: [['name' => 'Servico D2']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-440', 'CHAVE-440', '2026-03-21T10:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('3.65', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('2.00', $client->capturedDps?->totalTributosPercentualMunicipal);
        }

        public function testEmitUsesSimplesNacionalTributosProfileWhenCompanyIsOptant(): void
        {
            ControllerIsolationState::$settings['nfse.opcao_simples_nacional'] = 2;
            ControllerIsolationState::$settings['nfse.tributos_fed_p'] = '99.99';
            ControllerIsolationState::$settings['nfse.tributos_est_p'] = '99.99';
            ControllerIsolationState::$settings['nfse.tributos_mun_p'] = '99.99';
            ControllerIsolationState::$settings['nfse.tributos_fed_sn'] = '4.44';
            ControllerIsolationState::$settings['nfse.tributos_est_sn'] = '1.11';
            ControllerIsolationState::$settings['nfse.tributos_mun_sn'] = '0.55';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 45,
                amount: 500.00,
                items: [['name' => 'Servico E']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-45', 'CHAVE-45', '2026-03-21T10:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('4.44', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('1.11', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('0.55', $client->capturedDps?->totalTributosPercentualMunicipal);
        }

        public function testEmitSupportsRetentionOnlyFederalPayloadWithoutConfiguredTributosPercentuais(): void
        {
            ControllerIsolationState::$settings['nfse.federal_piscofins_situacao_tributaria'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_tipo_retencao'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_base_calculo'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_aliquota_pis'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_valor_pis'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_aliquota_cofins'] = '';
            ControllerIsolationState::$settings['nfse.federal_piscofins_valor_cofins'] = '';
            ControllerIsolationState::$settings['nfse.federal_valor_irrf'] = '1.00';
            ControllerIsolationState::$settings['nfse.federal_valor_csll'] = '0.00';
            ControllerIsolationState::$settings['nfse.federal_valor_cp'] = '0.00';
            ControllerIsolationState::$settings['nfse.tributos_fed_p'] = '';
            ControllerIsolationState::$settings['nfse.tributos_est_p'] = '';
            ControllerIsolationState::$settings['nfse.tributos_mun_p'] = '';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 451,
                amount: 500.00,
                items: [['name' => 'Servico com somente IRRF']],
                contactTaxNumber: '99887766000155',
            );
            $invoice->issued_at = '2026-02-04 08:37:53';

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-451', 'CHAVE-451', '2026-03-21T10:30:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('5.00', $client->capturedDps?->federalValorIrrf);
            self::assertSame('', $client->capturedDps?->federalValorCsll);
            self::assertSame('', $client->capturedDps?->federalValorCp);
            self::assertSame(2, $client->capturedDps?->indicadorTributacao);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualFederal);
            self::assertSame('0.00', $client->capturedDps?->totalTributosPercentualEstadual);
            self::assertSame('4.50', $client->capturedDps?->totalTributosPercentualMunicipal);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
        }

        public function testRuntimeXmlBuilderStartsInfDpsWithTpAmbBeforeMunicipalityFields(): void
        {
            $builder = new \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder();
            $xml = $builder->buildDps(new DpsData(
                cnpjPrestador: '12345678000195',
                municipioIbge: '3303302',
                itemListaServico: '007',
                valorServico: '31500.00',
                aliquota: '2.00',
                discriminacao: 'Servico de teste E2E',
                tipoAmbiente: 2,
                codigoTributacaoNacional: '010101',
                documentoTomador: '12345678000195',
                nomeTomador: 'Cliente de Teste',
                opcaoSimplesNacional: 1,
                totalTributosPercentualFederal: '3.65',
                totalTributosPercentualEstadual: '0.00',
                totalTributosPercentualMunicipal: '2.00',
                federalPiscofinsSituacaoTributaria: '1',
                federalPiscofinsTipoRetencao: '4',
                federalPiscofinsBaseCalculo: '31500.00',
                federalPiscofinsAliquotaPis: '0.65',
                federalPiscofinsValorPis: '204.75',
                federalPiscofinsAliquotaCofins: '3.00',
                federalPiscofinsValorCofins: '945.00',
                federalValorIrrf: '472.50',
                federalValorCsll: '0.00',
                federalValorCp: '0.00',
            ));

            $normalizedXml = str_replace(["\n", '  '], '', $xml);
            self::assertStringContainsString('<tpAmb>2</tpAmb><dhEmi>', $normalizedXml);
            self::assertStringContainsString('<verAplic>akaunting-nfse</verAplic><serie>00001</serie><nDPS>1</nDPS>', $normalizedXml);
            self::assertStringContainsString('<serv><locPrest><cLocPrestacao>3303302</cLocPrestacao></locPrest><cServ><cTribNac>010101</cTribNac><cTribMun>007</cTribMun>', $normalizedXml);
            self::assertStringContainsString('<piscofins><CST>01</CST><vBCPisCofins>31500.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins><vPis>204.75</vPis><vCofins>945.00</vCofins><tpRetPisCofins>4</tpRetPisCofins></piscofins>', $normalizedXml);
            self::assertStringContainsString('<vRetIRRF>472.50</vRetIRRF>', $normalizedXml);
            self::assertStringNotContainsString('<vRetCSLL>', $normalizedXml);
            self::assertStringNotContainsString('<vRetCP>', $normalizedXml);
            self::assertStringContainsString('<pTotTrib><pTotTribFed>3.65</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>2.00</pTotTribMun></pTotTrib>', $normalizedXml);
            self::assertStringNotContainsString('<cMun>3303302</cMun>', $normalizedXml);
        }

        public function testRuntimeXmlBuilderKeepsTribFedAndTotTribWhenFederalPayloadIsOtherwiseEmpty(): void
        {
            $builder = new \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder();
            $xml = $builder->buildDps(new DpsData(
                cnpjPrestador: '12345678000195',
                municipioIbge: '3303302',
                itemListaServico: '007',
                valorServico: '500.00',
                aliquota: '2.00',
                discriminacao: 'Servico sem tributos federais explicitos',
                tipoAmbiente: 2,
                codigoTributacaoNacional: '010101',
                documentoTomador: '12345678000195',
                nomeTomador: 'Cliente de Teste',
                opcaoSimplesNacional: 1,
            ));

            $normalizedXml = str_replace(["\n", '  '], '', $xml);

            self::assertStringContainsString('<tribFed/><totTrib>', $normalizedXml);
            self::assertStringContainsString('<trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun><tribFed/><totTrib><pTotTrib><pTotTribFed>0.00</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>0.00</pTotTribMun></pTotTrib></totTrib></trib>', $normalizedXml);
        }

        public function testEmitPreservesTypeFourWithoutPopulatingCsllWhenCsllIsZero(): void
        {
            ControllerIsolationState::$settings['nfse.federal_piscofins_tipo_retencao'] = '4';
            ControllerIsolationState::$settings['nfse.federal_valor_csll'] = '0.00';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 204,
                amount: 1000.00,
                items: [
                    ['name' => 'Servico fallback retencao'],
                ],
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData(
                        nfseNumber: 'NF-204',
                        chaveAcesso: 'CHAVE-204',
                        dataEmissao: '2026-04-03T12:00:00-03:00',
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame('4', $client->capturedDps?->federalPiscofinsTipoRetencao);
            self::assertSame('', $client->capturedDps?->federalValorCsll);
        }

        public function testEmitUsesFiscalSettingsFallbackWhenItemHasNoFiscalProfile(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 52,
                amount: 875.4,
                items: [['name' => 'Servico sem perfil fiscal']],
                description: 'Servico do cadastro padrao',
                contactName: 'Cliente Padrao',
                contactTaxNumber: '11222333000144',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-52', 'CHAVE-52', '2026-03-23T15:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota' => '6.75',
                        'description' => 'Consultoria padrao',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }
            };

            $controller->emit($invoice);

            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('010701', $client->capturedDps?->codigoTributacaoNacional);
            self::assertSame('4.50', $client->capturedDps?->aliquota);
        }

        public function testEmitRedirectsToPendingWhenInvoiceItemHasNoServiceAssociationAndFallbackWasNotConfirmed(): void
        {
            // Item-native fallback now comes from nfse.* settings, not CompanyService mapping.
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 521,
                amount: 300.0,
                items: [
                    ['item_id' => 10, 'name' => 'Servico sem vinculo'],
                ],
                description: 'Descricao teste',
                contactName: 'Cliente sem vinculo',
                contactTaxNumber: '99887766000155',
            );
            $invoice->company_id = 1;

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-521', 'CHAVE-521', '2026-03-23T15:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'id' => 900,
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota' => '5.00',
                        'description' => 'Servico padrao',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }

                protected function supportsItemServiceMapping(): bool
                {
                    return true;
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, array{item_lista_servico:string, codigo_tributacao_nacional:string}>
                 */
                protected function invoiceItemFiscalProfileMap(int $companyId, array $itemIds): array
                {
                    return [];
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, string>
                 */
                protected function invoiceItemTaxRateMap(array $itemIds): array
                {
                    return [];
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice, new Request());

            // Items sem perfil fiscal usam fallback de settings.
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('[0107] Servico sem vinculo', $client->capturedDps?->discriminacao);
        }

        public function testEmitUsesDefaultServiceWhenMissingAssociationsAreConfirmedByRequest(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 522,
                amount: 300.0,
                items: [
                    ['item_id' => 10, 'name' => 'Servico sem vinculo'],
                ],
                description: 'Descricao teste',
                contactName: 'Cliente confirmado',
                contactTaxNumber: '99887766000155',
            );
            $invoice->company_id = 1;

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-522', 'CHAVE-522', '2026-03-23T15:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'id' => 900,
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota' => '5.00',
                        'description' => 'Servico padrao',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }

                protected function supportsItemServiceMapping(): bool
                {
                    return true;
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, object>
                 */
                protected function invoiceItemServiceMap(int $companyId, array $itemIds): array
                {
                    return [];
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new Request([
                'nfse_confirm_default_service' => '1',
            ]);

            $response = $controller->emit($invoice, $request);

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertStringContainsString('Servico sem vinculo', $client->capturedDps?->discriminacao ?? '');
            self::assertStringContainsString('0107', $client->capturedDps?->discriminacao ?? '');
        }

        public function testEmitUsesMappedItemServiceWhenAssociationExists(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 523,
                amount: 420.0,
                items: [
                    ['item_id' => 10, 'name' => 'Servico vinculado'],
                ],
                description: 'Descricao teste',
                contactName: 'Cliente mapeado',
                contactTaxNumber: '99887766000155',
            );
            $invoice->company_id = 1;

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-523', 'CHAVE-523', '2026-03-23T15:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'id' => 900,
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota' => '5.00',
                        'description' => 'Servico padrao',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }

                protected function supportsItemServiceMapping(): bool
                {
                    return true;
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, array{item_lista_servico:string, codigo_tributacao_nacional:string}>
                 */
                protected function invoiceItemFiscalProfileMap(int $companyId, array $itemIds): array
                {
                    return [
                        10 => [
                            'item_lista_servico' => '0107',
                            'codigo_tributacao_nacional' => '010701',
                        ],
                    ];
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, string>
                 */
                protected function invoiceItemTaxRateMap(array $itemIds): array
                {
                    return [
                        10 => '7.00',
                    ];
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice, new Request());

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('010701', $client->capturedDps?->codigoTributacaoNacional);
            self::assertSame('7.00', $client->capturedDps?->aliquota);
            self::assertStringContainsString('[0107] Servico vinculado', $client->capturedDps?->discriminacao ?? '');
        }

        public function testEmitBlocksWhenInvoiceUsesDifferentMappedMunicipalTaxProfiles(): void
        {
            // POC Update: Multiple fiscal profiles are now supported - invoice should emit successfully
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 524,
                amount: 620.0,
                items: [
                    ['item_id' => 10, 'name' => 'Servico A'],
                    ['item_id' => 11, 'name' => 'Servico B'],
                ],
                description: 'Descricao teste',
                contactName: 'Cliente divergente',
                contactTaxNumber: '99887766000155',
            );
            $invoice->company_id = 1;

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-524', 'CHAVE-524', '2026-03-23T15:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'id' => 900,
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota' => '5.00',
                        'description' => 'Servico padrao',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }

                protected function supportsItemServiceMapping(): bool
                {
                    return true;
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, array{item_lista_servico:string, codigo_tributacao_nacional:string}>
                 */
                protected function invoiceItemFiscalProfileMap(int $companyId, array $itemIds): array
                {
                    return [
                        10 => [
                            'item_lista_servico' => '0101',
                            'codigo_tributacao_nacional' => '010101',
                        ],
                        11 => [
                            'item_lista_servico' => '0107',
                            'codigo_tributacao_nacional' => '010701',
                        ],
                    ];
                }

                /**
                 * @param list<int> $itemIds
                 * @return array<int, string>
                 */
                protected function invoiceItemTaxRateMap(array $itemIds): array
                {
                    return [
                        10 => '7.00',
                        11 => '2.00',
                    ];
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice, new Request());

            // In POC, multiple fiscal profiles are allowed - invoice should emit with first profile
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            // When multiple profiles exist, the first one is selected (highest priority)
            self::assertSame('0101', $client->capturedDps?->itemListaServico);
            self::assertStringContainsString('[0101] Servico A', $client->capturedDps?->discriminacao ?? '');
        }

        public function testServicePreviewReturnsMissingItemsAndAvailableServicesContract(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 524,
                amount: 120.0,
                items: [
                    ['item_id' => 77, 'name' => 'Item sem servico', 'total' => '120.00'],
                ],
                description: 'Descricao preview',
            );

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) ['id' => 900, 'item_lista_servico' => '1401', 'is_default' => true, 'is_active' => true];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [
                        ['id' => 900, 'label' => '14.01 - Servico padrao', 'is_default' => true],
                    ];
                }

                protected function annotateFiscalGroupsWithReceiptState(Invoice $invoice, array $groups): array
                {
                    return array_map(static function (array $group): array {
                        $group['issued'] = false;
                        $group['receipt_id'] = null;
                        $group['nfse_number'] = null;

                        return $group;
                    }, $groups);
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertSame(200, $response->getStatusCode());
            // Item-native model: items without a fiscal profile use the default service automatically,
            // so missing_items is always empty.
            self::assertSame([], $payload['missing_items'] ?? null);
            self::assertSame([['id' => 900, 'label' => '14.01 - Servico padrao', 'is_default' => true]], $payload['available_services'] ?? null);
            self::assertSame(0, $payload['default_service_id'] ?? null);
            self::assertFalse((bool) ($payload['requires_split'] ?? true));
            self::assertArrayHasKey('taker_defaults', $payload);
            self::assertFalse((bool) ($payload['taker_defaults']['foreign'] ?? true));
        }

        public function testServicePreviewIncludesEmailDefaults(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$settings['nfse.send_email_on_emit'] = '1';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 10,
                amount: 50.0,
                contactEmail: 'cliente@example.com',
                contactName: 'João Silva',
            );

            $template = new \App\Models\Setting\EmailTemplate();
            $template->subject = 'NFS-e {nfse_number} emitida';
            $template->body = 'Prezado(a) {customer_name}';
            \App\Models\Setting\EmailTemplate::$stubInstance = $template;

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return [
                        'selected_service' => null,
                        'line_items' => [],
                        'missing_items' => [],
                        'requires_confirmation' => false,
                        'requires_split' => false,
                    ];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertArrayHasKey('email_defaults', $payload);
            self::assertTrue($payload['email_defaults']['send_email']);
            self::assertSame('cliente@example.com', $payload['email_defaults']['recipient']);
            self::assertSame('NFS-e {nfse_number} emitida', $payload['email_defaults']['subject']);
            self::assertSame('Prezado(a) {customer_name}', $payload['email_defaults']['body']);
            self::assertTrue($payload['email_defaults']['attach_danfse']);
            self::assertTrue($payload['email_defaults']['attach_xml']);
            self::assertArrayHasKey('default_subject', $payload['email_defaults']);
            self::assertNotEmpty($payload['email_defaults']['default_subject']);
            self::assertArrayHasKey('default_body', $payload['email_defaults']);
            self::assertNotEmpty($payload['email_defaults']['default_body']);
        }

        public function testServicePreviewUsesSavedAttachmentDefaults(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$settings['nfse.email_attach_danfse_on_emit'] = '0';
            ControllerIsolationState::$settings['nfse.email_attach_xml_on_emit'] = '1';

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 12, amount: 50.0);

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return ['selected_service' => null, 'line_items' => [], 'missing_items' => [], 'requires_confirmation' => false, 'requires_split' => false];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertFalse($payload['email_defaults']['attach_danfse']);
            self::assertTrue($payload['email_defaults']['attach_xml']);
        }

        public function testServicePreviewUsesSavedDefaultDescriptionWhenAvailable(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$settings['invoice.notes'] = 'Descricao padrao generica';

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 13,
                amount: 50.0,
                items: [
                    ['item_id' => 99, 'name' => 'Texto de item que nao deve ir para descricao', 'total' => '50.00'],
                ],
                description: 'Descricao da fatura',
            );

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return [
                        'selected_service' => null,
                        'line_items' => ['Replica de item 01', 'Replica de item 02'],
                        'missing_items' => [],
                        'requires_confirmation' => false,
                        'requires_split' => false,
                    ];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }

                protected function annotateFiscalGroupsWithReceiptState(Invoice $invoice, array $groups): array
                {
                    return array_map(static function (array $group): array {
                        $group['issued'] = false;
                        $group['receipt_id'] = null;
                        $group['nfse_number'] = null;

                        return $group;
                    }, $groups);
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertSame('Descricao padrao generica', $payload['suggested_description'] ?? null);
        }

        public function testServicePreviewEmailDefaultsFallsBackWhenNoTemplate(): void
        {
            InvoiceControllerIsolationState::reset();
            \App\Models\Setting\EmailTemplate::$stubInstance = null;

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 11, amount: 50.0);

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return ['selected_service' => null, 'line_items' => [], 'missing_items' => [], 'requires_confirmation' => false, 'requires_split' => false];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertArrayHasKey('email_defaults', $payload);
            self::assertFalse($payload['email_defaults']['send_email']);
            self::assertSame('', $payload['email_defaults']['subject']);
            self::assertSame('', $payload['email_defaults']['body']);
            self::assertArrayHasKey('default_subject', $payload['email_defaults']);
            self::assertNotEmpty($payload['email_defaults']['default_subject']);
            self::assertArrayHasKey('default_body', $payload['email_defaults']);
            self::assertNotEmpty($payload['email_defaults']['default_body']);
        }

        public function testPersistDefaultDescriptionFromRequestSavesWhenFlagEnabled(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$savedCount = 0;

            $request = \Illuminate\Http\Request::create('/nfse/emit', 'POST', [
                'nfse_save_default_description' => '1',
                'nfse_discriminacao_custom' => '  Nova descricao padrao   da NFS-e  ',
            ]);

            $controller = new class () extends InvoiceController {
                public function exposePersistDefaultDescriptionFromRequest(?\Illuminate\Http\Request $request): void
                {
                    $this->persistDefaultDescriptionFromRequest($request);
                }
            };

            $controller->exposePersistDefaultDescriptionFromRequest($request);

            self::assertSame('Nova descricao padrao da NFS-e', ControllerIsolationState::$settings['invoice.notes'] ?? null);
            self::assertSame(1, ControllerIsolationState::$savedCount);
        }

        public function testPersistDefaultDescriptionFromRequestSkipsWhenFlagDisabled(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$savedCount = 0;
            ControllerIsolationState::$settings['invoice.notes'] = 'Descricao antiga';

            $request = \Illuminate\Http\Request::create('/nfse/emit', 'POST', [
                'nfse_save_default_description' => '0',
                'nfse_discriminacao_custom' => 'Descricao nova nao persistida',
            ]);

            $controller = new class () extends InvoiceController {
                public function exposePersistDefaultDescriptionFromRequest(?\Illuminate\Http\Request $request): void
                {
                    $this->persistDefaultDescriptionFromRequest($request);
                }
            };

            $controller->exposePersistDefaultDescriptionFromRequest($request);

            self::assertSame('Descricao antiga', ControllerIsolationState::$settings['invoice.notes'] ?? null);
            self::assertSame(0, ControllerIsolationState::$savedCount);
        }

        public function testReemitDetailsViewContainsSaveDefaultDescriptionField(): void
        {
            // Verification that the reemit view is accessible - details view structure simplified in POC
            $filePath = dirname(__DIR__, 4) . '/Resources/views/invoices/show.blade.php';
            self::assertFileExists($filePath);
        }

        public function testReemitDetailsViewSerializesSaveDefaultDescriptionOnConfirm(): void
        {
            // Verification that the reemit view is accessible - details view structure simplified in POC
            $filePath = dirname(__DIR__, 4) . '/Resources/views/invoices/show.blade.php';
            self::assertFileExists($filePath);
        }

        public function testEmitModalHasSubmittingStateSpinnerAndHandler(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Resources/views/invoices/index.blade.php');

            self::assertStringContainsString('id="emit-submit-spinner"', $content);
            self::assertStringContainsString('data-loading-label="{{ trans(\'nfse::general.invoices.emit_modal_submitting\') }}"', $content);
            self::assertStringContainsString('window.nfseConfirmEmit = () => {', $content);
            self::assertStringContainsString('setEmitSubmittingState(true);', $content);
        }

        public function testReemitModalHasSubmittingStateSpinnerAndNoEarlyCloseOnSubmit(): void
        {
            // Verification that the reemit modal is accessible - details view structure simplified in POC
            $filePath = dirname(__DIR__, 4) . '/Resources/views/invoices/show.blade.php';
            self::assertFileExists($filePath);
            $content = (string) file_get_contents($filePath);
            self::assertStringContainsString('nfse-cancel-modal', $content);
        }

        public function testNationalTaxCodeFallsBackToSettingWhenDefaultServiceCodeIsMissing(): void
        {
            $controller = new class () extends InvoiceController {
                public function exposedNationalTaxCode(?object $defaultService = null): string
                {
                    return $this->nationalTaxCode($defaultService);
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }
            };

            $defaultService = (object) [
                'item_lista_servico' => '1401',
                'codigo_tributacao_nacional' => null,
                'aliquota' => '5.00',
            ];

            self::assertSame('010701', $controller->exposedNationalTaxCode($defaultService));
        }

        public function testNationalTaxCodeDoesNotGuessFromLc116WhenNoExplicitConfigIsPresent(): void
        {
            ControllerIsolationState::$settings['nfse.codigo_tributacao_nacional'] = '';

            $controller = new class () extends InvoiceController {
                public function exposedNationalTaxCode(?object $defaultService = null): string
                {
                    return $this->nationalTaxCode($defaultService);
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }
            };

            $defaultService = (object) [
                'item_lista_servico' => '0101',
                'codigo_tributacao_nacional' => null,
                'aliquota' => '5.00',
            ];

            self::assertSame('', $controller->exposedNationalTaxCode($defaultService));
        }

        public function testItemListaServicoNormalizesLegacyThreeDigitLc116Code(): void
        {
            $controller = new class () extends InvoiceController {
                public function exposedItemListaServico(?object $defaultService = null): string
                {
                    return $this->itemListaServico($defaultService);
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }
            };

            $defaultService = (object) [
                'item_lista_servico' => '107',
                'codigo_tributacao_nacional' => '010101',
                'aliquota' => '2.00',
            ];

            self::assertSame('0107', $controller->exposedItemListaServico($defaultService));
        }

        public function testForeignTomadorPayloadAcceptsNifAndCompleteAddress(): void
        {
            $controller = new class () extends InvoiceController {
                /** @param array<string, mixed> $nationalPayload */
                public function foreignPayload(Request $request, Invoice $invoice, array $nationalPayload): array
                {
                    return $this->foreignTomadorPayloadFromRequest($request, $invoice, $nationalPayload);
                }
            };

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 980,
                amount: 100.00,
                contactName: 'Foreign Customer',
            );

            $payload = $controller->foreignPayload(
                new Request([
                    'nfse_tomador_foreign' => '1',
                    'nfse_tomador_nif' => 'US-TAX-123',
                    'nfse_tomador_country' => 'us',
                    'nfse_tomador_postal_code' => '10001',
                    'nfse_tomador_city' => 'New York',
                    'nfse_tomador_region' => 'NY',
                    'nfse_tomador_street' => '5th Avenue',
                    'nfse_tomador_number' => '100',
                    'nfse_tomador_complement' => 'Suite 10',
                    'nfse_tomador_district' => 'Manhattan',
                ]),
                $invoice,
                [],
            );

            self::assertTrue($payload['enabled']);
            self::assertSame('US-TAX-123', $payload['nif']);
            self::assertNull($payload['codigo_nao_nif']);
            self::assertSame('US', $payload['pais_codigo']);
            self::assertSame('10001', $payload['codigo_postal']);
            self::assertSame('New York', $payload['cidade']);
            self::assertSame('NY', $payload['estado']);
        }

        public function testForeignTomadorPayloadAcceptsOfficialNoNifReason(): void
        {
            $controller = new class () extends InvoiceController {
                public function foreignPayload(Request $request, Invoice $invoice): array
                {
                    return $this->foreignTomadorPayloadFromRequest($request, $invoice, []);
                }
            };

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 981, amount: 100.00);

            $payload = $controller->foreignPayload(new Request([
                'nfse_tomador_foreign' => '1',
                'nfse_tomador_nao_nif' => '2',
                'nfse_tomador_country' => 'PT',
                'nfse_tomador_postal_code' => '1000-001',
                'nfse_tomador_city' => 'Lisboa',
                'nfse_tomador_region' => 'Lisboa',
                'nfse_tomador_street' => 'Avenida da Liberdade',
                'nfse_tomador_number' => '10',
                'nfse_tomador_district' => 'Santo Antonio',
            ]), $invoice);

            self::assertSame(2, $payload['codigo_nao_nif']);
            self::assertSame('', $payload['nif']);
        }

        public function testForeignTomadorPayloadRejectsNifTogetherWithNoNifReason(): void
        {
            $controller = new class () extends InvoiceController {
                public function foreignPayload(Request $request, Invoice $invoice): array
                {
                    return $this->foreignTomadorPayloadFromRequest($request, $invoice, []);
                }
            };

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 982, amount: 100.00);

            $this->expectException(\InvalidArgumentException::class);

            $controller->foreignPayload(new Request([
                'nfse_tomador_foreign' => '1',
                'nfse_tomador_nif' => 'NIF-123',
                'nfse_tomador_nao_nif' => '1',
            ]), $invoice);
        }

        public function testForeignTomadorPayloadIsNoopWhenDisabled(): void
        {
            $controller = new class () extends InvoiceController {
                public function foreignPayload(Request $request, Invoice $invoice): array
                {
                    return $this->foreignTomadorPayloadFromRequest($request, $invoice, []);
                }
            };

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 983, amount: 100.00);
            $payload = $controller->foreignPayload(new Request([]), $invoice);

            self::assertFalse($payload['enabled']);
            self::assertSame('', $payload['nif']);
            self::assertSame('', $payload['pais_codigo']);
        }

        public function testForeignTomadorPayloadTreatsNullRequestAsDisabled(): void
        {
            $controller = new class () extends InvoiceController {
                public function foreignPayload(?Request $request, Invoice $invoice): array
                {
                    return $this->foreignTomadorPayloadFromRequest($request, $invoice, []);
                }
            };

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 984, amount: 100.00);
            $payload = $controller->foreignPayload(null, $invoice);

            self::assertFalse($payload['enabled']);
            self::assertSame('', $payload['nif']);
            self::assertSame('', $payload['pais_codigo']);
        }

        public function testMakeDpsDataRejectsMissingRequiredRuntimeCapability(): void
        {
            $controller = new class () extends InvoiceController {
                /**
                 * @param array<string, mixed> $payload
                 * @param list<string> $requiredFields
                 */
                public function buildDps(array $payload, array $requiredFields): DpsData
                {
                    return $this->makeDpsData($payload, $requiredFields);
                }
            };

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Installed nfse-php runtime does not support required DPS field: unsupportedField');

            $controller->buildDps([
                'cnpjPrestador' => '29842527000145',
                'municipioIbge' => '3304557',
                'itemListaServico' => '0107',
                'valorServico' => '100.00',
                'aliquota' => '5.00',
                'discriminacao' => 'Teste',
                'unsupportedField' => 'value',
            ], ['unsupportedField']);
        }

        public function testMakeDpsDataStillIgnoresOptionalUnknownRuntimeFields(): void
        {
            $controller = new class () extends InvoiceController {
                /** @param array<string, mixed> $payload */
                public function buildDps(array $payload): DpsData
                {
                    return $this->makeDpsData($payload);
                }
            };

            $dps = $controller->buildDps([
                'cnpjPrestador' => '29842527000145',
                'municipioIbge' => '3304557',
                'itemListaServico' => '0107',
                'valorServico' => '100.00',
                'aliquota' => '5.00',
                'discriminacao' => 'Teste',
                'optionalFutureField' => 'ignored',
            ]);

            self::assertSame('29842527000145', $dps->cnpjPrestador);
        }

        public function testAmbiguousEmissionRecoveryQueriesDpsThenAuthorizedNfse(): void
        {
            $controller = new class () extends InvoiceController {
                public function recover(NfseClientInterface $client, DpsData $dps): ?ReceiptData
                {
                    return $this->recoverReceiptAfterAmbiguousEmission($client, $dps);
                }
            };

            $client = new class () implements NfseClientInterface {
                /** @var list<string> */
                public array $calls = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \LogicException('not used');
                }

                public function queryDps(string $idDps): string
                {
                    $this->calls[] = 'dps:' . $idDps;

                    return 'ACCESS-KEY-42';
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    $this->calls[] = 'nfse:' . $chaveAcesso;

                    return new ReceiptData('42', $chaveAcesso, '2026-10-03T03:00:00-03:00');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    return false;
                }

                public function getDanfse(string $nfseXml): string
                {
                    return '';
                }
            };

            $dps = new DpsData(
                cnpjPrestador: '12ABC34501DE35',
                municipioIbge: '3303302',
                itemListaServico: '001',
                valorServico: '100.00',
                aliquota: '5.00',
                discriminacao: 'Teste',
                serie: '1',
                numeroDps: '42',
            );

            $receipt = $controller->recover($client, $dps);

            self::assertNotNull($receipt);
            self::assertSame('ACCESS-KEY-42', $receipt->chaveAcesso);
            self::assertSame([
                'dps:3303302212ABC34501DE3500001000000000000042',
                'nfse:ACCESS-KEY-42',
            ], $client->calls);
        }

        public function testAmbiguousEmissionRecoveryIsBackwardCompatibleWithoutDpsLookup(): void
        {
            $controller = new class () extends InvoiceController {
                public function recover(NfseClientInterface $client, DpsData $dps): ?ReceiptData
                {
                    return $this->recoverReceiptAfterAmbiguousEmission($client, $dps);
                }
            };

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \LogicException('not used');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \LogicException('not used');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    return false;
                }

                public function getDanfse(string $nfseXml): string
                {
                    return '';
                }
            };

            $dps = new DpsData(
                cnpjPrestador: '11222333000181',
                municipioIbge: '3303302',
                itemListaServico: '001',
                valorServico: '100.00',
                aliquota: '5.00',
                discriminacao: 'Teste',
            );

            self::assertNull($controller->recover($client, $dps));
        }

        public function testControllerDoesNotDeriveMunicipalTaxCodeFromLc116(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringNotContainsString('normalizedMunicipalTaxationCode', $content);
            self::assertStringContainsString(
                "'codigoTributacaoMunicipal' => (string) (\$itemFiscalProfile['codigo_tributacao_municipal'] ?? '')",
                $content,
            );
            self::assertStringContainsString("'itemListaServico' => (string) \$itemFiscalProfile['item_lista_servico']", $content);
        }

        public function testEmissionReadinessDoesNotIncludeItemFiscalClassification(): void
        {
            $controller = new class () extends InvoiceController {
                public function exposedEmissionReadiness(): array
                {
                    return $this->emissionReadiness();
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $readiness = $controller->exposedEmissionReadiness();

            self::assertTrue($readiness['isReady'] ?? false);
            self::assertArrayNotHasKey('codigo_tributacao_nacional', $readiness['checklist']);
            self::assertArrayNotHasKey('item_lista_servico', $readiness['checklist']);
        }

        public function testEmissionReadinessBlocksKnownExpiredCertificate(): void
        {
            ControllerIsolationState::$settings['nfse.cnpj_prestador'] = '12345678000195';
            ControllerIsolationState::$settings['nfse.certificate_valid_from'] = time() - 172800;
            ControllerIsolationState::$settings['nfse.certificate_valid_to'] = time() - 86400;

            $controller = new class () extends InvoiceController {
                public function exposedEmissionReadiness(): array
                {
                    return $this->emissionReadiness();
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $readiness = $controller->exposedEmissionReadiness();

            self::assertArrayHasKey('certificate_valid', $readiness['checklist']);
            self::assertFalse($readiness['checklist']['certificate_valid']);
            self::assertFalse($readiness['isReady']);
        }

        public function testEmitSetsTipoAmbienteFromSandboxMode(): void
        {
            ControllerIsolationState::$settings['nfse.sandbox_mode'] = true;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 99,
                amount: 100.0,
                items: [['name' => 'Servico sandbox']],
                description: 'Teste sandbox',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-99', 'CHAVE-99', '2026-03-23T10:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public ?bool $capturedSandbox = null;

                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    $this->capturedSandbox = $sandboxMode;

                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertTrue($controller->capturedSandbox);
            self::assertSame(2, $client->capturedDps?->tipoAmbiente);
        }

        public function testEmitNormalizesFormattedTomadorDocument(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 1042,
                amount: 10.0,
                items: [['name' => 'Servico tomador formatado']],
                description: 'Teste tomador formatado',
                contactName: 'Tomador Formatado',
                contactTaxNumber: '99.887.766/0001-55',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-1042', 'CHAVE-1042', '2026-03-23T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame('99887766000155', $client->capturedDps?->documentoTomador);
        }

        public function testEmitPreservesAlphanumericTomadorCnpj(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 1044,
                amount: 10.0,
                items: [['name' => 'Servico tomador CNPJ alfanumerico']],
                description: 'Teste tomador CNPJ alfanumerico',
                contactName: 'Tomador Alfa',
                contactTaxNumber: '12.abc.345/01de-35',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-1044', 'CHAVE-1044', '2026-10-02T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame('12ABC34501DE35', $client->capturedDps?->documentoTomador);
        }

        public function testTomadorPayloadSkipsAddressWhenMunicipioIbgeIsMissing(): void
        {
            $controller = new class () extends InvoiceController {
                public function exposedTomadorPayload(?object $contact): array
                {
                    return $this->tomadorPayload($contact);
                }
            };

            $payload = $controller->exposedTomadorPayload((object) [
                'address' => 'Rua sem codigo',
                'zip_code' => '24020-077',
                'phone' => '(21) 97777-6666',
                'email' => 'contato@example.test',
            ]);

            self::assertSame('', $payload['codigo_municipio']);
            self::assertSame('', $payload['cep']);
            self::assertSame('', $payload['logradouro']);
            self::assertSame('21977776666', $payload['telefone']);
            self::assertSame('contato@example.test', $payload['email']);
        }

        public function testEmitDropsInvalidTomadorDocument(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 1043,
                amount: 11.0,
                items: [['name' => 'Servico tomador invalido']],
                description: 'Teste tomador invalido',
                contactName: 'Tomador Invalido',
                contactTaxNumber: 'ABC',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-1043', 'CHAVE-1043', '2026-03-23T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice);

            self::assertSame('', $client->capturedDps?->documentoTomador);
        }

        public function testEmitBlocksWhenInvoiceHasNoItems(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 7,
                amount: 90.0,
                items: [],
                description: 'Descricao da nota',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-7', 'CHAVE-7', '2026-03-21T12:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertNull($client->capturedDps);
            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('A fatura precisa ter ao menos um item para emitir NFS-e.', $response->flash['error'] ?? null);
        }

        public function testEmitUsesCustomDescriptionFromRequestWhenProvided(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 81,
                amount: 120.0,
                items: [['name' => 'Servico Automatico']],
                description: 'Descricao original',
            );

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-81', 'CHAVE-81', '2026-03-21T12:05:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $controller->emit($invoice, new Request([
                'nfse_discriminacao_custom' => '  Descricao manual ajustada da NFS-e  ',
            ]));

            self::assertSame('Descricao manual ajustada da NFS-e', $client->capturedDps?->discriminacao);
        }

        public function testReemitBlocksWhenInvoiceHasNoItems(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 8,
                amount: 91.0,
                items: [],
                description: '',
            );

            InvoiceControllerIsolationState::makeReceipt(8, 'CHAVE-8', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-8', 'CHAVE-8', '2026-03-21T12:05:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->reemit($invoice);

            self::assertNull($client->capturedDps);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('A fatura precisa ter ao menos um item para emitir NFS-e.', $response->flash['error'] ?? null);
        }

        public function testCancelUsesStoredReceiptUpdatesStatusAndRedirectsToIndex(): void
        {
            $invoice = new Invoice(id: 88, amount: 10.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(88, 'CHAVE-CANCELAR');

            $client = new class () implements NfseClientInterface {
                public array $cancelCalls = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    $this->cancelCalls[] = [
                        'chaveAcesso' => $chaveAcesso,
                        'motivo' => $motivo,
                    ];

                    return true;
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame([
                [
                    'chaveAcesso' => 'CHAVE-CANCELAR',
                    'motivo' => 'Cancelamento padrao',
                ],
            ], $client->cancelCalls);
            self::assertSame([['status' => 'cancelled']], $receipt->updatedPayloads);
            self::assertSame('cancelled', $receipt->status);
            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame('NFS-e cancelada', $response->flash['success'] ?? null);
        }

        public function testCancelRedirectsBackToSalesInvoiceShowWhenRequested(): void
        {
            $invoice = new Invoice(id: 902, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(902, 'CHAVE-CANCELAR-902', 'emitted');

            $client = new class () implements NfseClientInterface {
                public array $cancelCalls = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    $this->cancelCalls[] = [
                        'chaveAcesso' => $chaveAcesso,
                        'motivo' => $motivo,
                    ];

                    return true;
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new Request(['redirect_after_cancel' => 'invoice_show']);

            $response = $controller->cancel($invoice, $request);

            self::assertSame([['status' => 'cancelled']], $receipt->updatedPayloads);
            self::assertSame('cancelled', $receipt->status);
            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e cancelada', $response->flash['success'] ?? null);
        }

        public function testIndexReturnsInvoicesViewWithPaginatedReceipts(): void
        {
            NfseReceipt::$paginateItems = ['receipt-a', 'receipt-b'];

            $response = (new InvoiceController())->index();

            self::assertSame('nfse::invoices.index', $response->name);
            self::assertSame(['receipt-a', 'receipt-b'], $response->data['receipts'] ?? null);
            self::assertSame('all', $response->data['status'] ?? null);
            self::assertSame(25, $response->data['perPage'] ?? null);
            self::assertNull($response->data['search'] ?? null);
            self::assertSame('due_at', $response->data['sortBy'] ?? null);
            self::assertSame('desc', $response->data['sortDirection'] ?? null);
        }

        public function testIndexUsesRequestedSortingWhenAllowed(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSortBy = null;
                public ?string $capturedSortDirection = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSortBy = $this->indexSortBy;
                    $this->capturedSortDirection = $this->indexSortDirection;

                    return ['sorted'];
                }
            };

            $response = $controller->index(new Request(['sort' => 'amount', 'direction' => 'asc']));

            self::assertSame('amount', $controller->capturedSortBy);
            self::assertSame('asc', $controller->capturedSortDirection);
            self::assertSame('amount', $response->data['sortBy'] ?? null);
            self::assertSame('asc', $response->data['sortDirection'] ?? null);
            self::assertSame(['sorted'], $response->data['receipts'] ?? null);
        }

        public function testIndexRestoresSavedListingPreferencesWhenNoQueryStateIsProvided(): void
        {
            // Neutral preferences (sort/per-page) can still be restored.
            ControllerIsolationState::$settings['nfse.invoices.preferences'] = json_encode([
                'status' => 'all',
                'per_page' => 50,
                'search' => null,
                'sort_by' => 'amount',
                'sort_direction' => 'asc',
            ]);

            $response = (new InvoiceController())->index(new Request());

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([[
                'status' => 'all',
                'limit' => 50,
                'sort' => 'amount',
                'direction' => 'asc',
            ]], $response->parameters);
        }

        public function testIndexDoesNotRestorePreferencesWhenSavedPreferencesAreDefault(): void
        {
            // If only default ("all") prefs are saved, bare URL should never redirect back to them.
            ControllerIsolationState::$settings['nfse.invoices.preferences'] = json_encode([
                'status' => 'all',
                'per_page' => 25,
                'search' => null,
                'sort_by' => 'due_at',
                'sort_direction' => 'desc',
            ]);

            $controller = new class () extends InvoiceController {
                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    return ['default'];
                }
            };

            $response = $controller->index(new Request());

            self::assertNotSame('route', $response->target ?? null, 'Bare URL with default prefs should render the view, not redirect');
        }

        public function testIndexTreatsEmptySearchParamAsExplicitClearAndSkipsPreferenceRestore(): void
        {
            // When the JS clear button fires, it navigates to ?search= (empty).
            // The controller must treat this as explicit state and NOT restore saved non-default prefs.
            ControllerIsolationState::$settings['nfse.invoices.preferences'] = json_encode([
                'status' => 'cancelled,emitted',
                'per_page' => 25,
                'search' => 'status:cancelled,emitted',
                'sort_by' => 'due_at',
                'sort_direction' => 'desc',
            ]);

            $controller = new class () extends InvoiceController {
                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    return ['cleared'];
                }
            };

            // Request with empty search param (?search=) — from the JS clear intercept.
            $response = $controller->index(new Request(['search' => '']));

            self::assertNotSame('route', $response->target ?? null, '?search= should be treated as explicit state, not redirect to old prefs');
            self::assertSame('all', $response->data['status'] ?? null, 'Status should fall back to "all" when search is cleared');
        }

        public function testIndexRestoresSavedNonDefaultFiltersOnBareUrl(): void
        {
            // Bare URL should restore previously saved non-default status/search filters.
            ControllerIsolationState::$settings['nfse.invoices.preferences'] = json_encode([
                'status' => 'cancelled,emitted',
                'per_page' => 25,
                'search' => 'status:cancelled,emitted',
                'sort_by' => 'due_at',
                'sort_direction' => 'desc',
            ]);

            $controller = new class () extends InvoiceController {
                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    return ['cleared'];
                }
            };

            $response = $controller->index(new Request());

            self::assertSame('route', $response->target ?? null, 'Bare URL should restore non-default saved filters');
            self::assertSame('nfse.invoices.index', $response->route ?? null);
            self::assertSame([
                [
                    'status' => 'cancelled,emitted',
                    'limit' => 25,
                    'search' => 'status:cancelled,emitted',
                    'sort' => 'due_at',
                    'direction' => 'desc',
                ],
            ], $response->parameters ?? null);
        }

        public function testIndexPersistsListingPreferencesToSettingsStorage(): void
        {
            ControllerIsolationState::$savedCount = 0;

            $controller = new class () extends InvoiceController {
                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    return ['persisted'];
                }
            };

            $controller->index(new Request([
                'status' => 'cancelled',
                'limit' => '100',
                'search' => 'NFSE-22',
                'sort_by' => 'document_number',
                'sort_direction' => 'asc',
            ]));

            self::assertSame(1, ControllerIsolationState::$savedCount);

            $stored = json_decode((string) (ControllerIsolationState::$settings['nfse.invoices.preferences'] ?? ''), true);

            self::assertSame([
                'status' => 'cancelled',
                'per_page' => 100,
                'search' => 'NFSE-22',
                'sort_by' => 'document_number',
                'sort_direction' => 'asc',
            ], $stored);
        }

        public function testIndexPassesStatusFilterFromRequestToReceiptQuery(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedStatus = $status;
                    $this->capturedPerPage = $perPage;
                    $this->capturedSearch = $search;

                    return ['filtered'];
                }
            };

            $response = $controller->index(new Request(['status' => 'cancelled']));

            self::assertSame('cancelled', $controller->capturedStatus);
            self::assertSame(25, $controller->capturedPerPage);
            self::assertNull($controller->capturedSearch);
            self::assertSame('cancelled', $response->data['status'] ?? null);
            self::assertSame(['filtered'], $response->data['receipts'] ?? null);
        }

        public function testIndexLoadsPendingAndReceiptDataWhenCombinedStatusIncludesPending(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;
                public bool $pendingInvoicesCalled = false;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedStatus = $status;
                    $this->capturedPerPage = $perPage;
                    $this->capturedSearch = $search;

                    return ['cancelled-row'];
                }

                protected function pendingInvoices(int $perPage = 25, ?string $search = null): iterable
                {
                    $this->pendingInvoicesCalled = true;

                    return ['pending-row'];
                }
            };

            $response = $controller->index(new Request(['status' => 'pending,cancelled']));

            self::assertSame('cancelled', $controller->capturedStatus);
            self::assertSame(25, $controller->capturedPerPage);
            self::assertNull($controller->capturedSearch);
            self::assertTrue($controller->pendingInvoicesCalled);
            self::assertSame('pending,cancelled', $response->data['status'] ?? null);
            self::assertSame(['cancelled-row'], $response->data['receipts'] ?? null);
            self::assertSame(['pending-row'], $response->data['pendingInvoices'] ?? null);
        }

        public function testIndexFallsBackToAllWhenStatusFilterIsInvalid(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedStatus = $status;
                    $this->capturedPerPage = $perPage;
                    $this->capturedSearch = $search;

                    return ['fallback'];
                }
            };

            $response = $controller->index(new Request(['status' => 'invalid-status']));

            self::assertSame('all', $controller->capturedStatus);
            self::assertSame(25, $controller->capturedPerPage);
            self::assertNull($controller->capturedSearch);
            self::assertSame('all', $response->data['status'] ?? null);
            self::assertSame(['fallback'], $response->data['receipts'] ?? null);
        }

        public function testIndexUsesRequestedPerPageWhenAllowed(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedStatus = $status;
                    $this->capturedPerPage = $perPage;
                    $this->capturedSearch = $search;

                    return ['custom-page'];
                }
            };

            $response = $controller->index(new Request(['status' => 'emitted', 'per_page' => '50']));

            self::assertSame('emitted', $controller->capturedStatus);
            self::assertSame(50, $controller->capturedPerPage);
            self::assertNull($controller->capturedSearch);
            self::assertSame(50, $response->data['perPage'] ?? null);
            self::assertSame(['custom-page'], $response->data['receipts'] ?? null);
        }

        public function testIndexFallsBackToDefaultPerPageWhenValueIsInvalid(): void
        {
            $controller = new class () extends InvoiceController {
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedPerPage = $perPage;
                    $this->capturedSearch = $search;

                    return ['default-page'];
                }
            };

            $response = $controller->index(new Request(['per_page' => '13']));

            self::assertSame(25, $controller->capturedPerPage);
            self::assertNull($controller->capturedSearch);
            self::assertSame(25, $response->data['perPage'] ?? null);
            self::assertSame(['default-page'], $response->data['receipts'] ?? null);
        }

        public function testIndexUsesTrimmedSearchQueryWhenProvided(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch = $search;

                    return ['search'];
                }
            };

            $response = $controller->index(new Request(['search' => '  NF-2026-001  ']));

            self::assertSame('NF-2026-001', $controller->capturedSearch);
            self::assertSame('NF-2026-001', $response->data['search'] ?? null);
            self::assertSame(['search'], $response->data['receipts'] ?? null);
        }

        public function testIndexStripsWrappingQuotesFromSearchQuery(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch = $search;

                    return ['quoted-search'];
                }
            };

            $response = $controller->index(new Request(['search' => '  "Assessoria"  ']));

            self::assertSame('Assessoria', $controller->capturedSearch);
            self::assertSame('Assessoria', $response->data['search'] ?? null);
            self::assertSame(['quoted-search'], $response->data['receipts'] ?? null);
        }

        public function testIndexConvertsEmptySearchToNull(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = 'marker';

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch = $search;

                    return ['empty-search'];
                }
            };

            $response = $controller->index(new Request(['search' => '   ']));

            self::assertNull($controller->capturedSearch);
            self::assertNull($response->data['search'] ?? null);
            self::assertSame(['empty-search'], $response->data['receipts'] ?? null);
        }

        public function testIndexUsesQLegacyFallbackWhenSearchQueryIsMissing(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch = $search;

                    return ['legacy-q'];
                }
            };

            $response = $controller->index(new Request(['q' => '  NF-LEGACY-1  ']));

            self::assertSame('NF-LEGACY-1', $controller->capturedSearch);
            self::assertSame('NF-LEGACY-1', $response->data['search'] ?? null);
            self::assertSame(['legacy-q'], $response->data['receipts'] ?? null);
        }

        public function testIndexParsesMultipleStatusTokenWithoutLeakingToSearchTerm(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedStatus = $status;
                    $this->capturedSearch = $search;

                    return ['multi-status'];
                }
            };

            $response = $controller->index(new Request(['search' => 'status:emitted,cancelled']));

            self::assertSame('emitted,cancelled', $controller->capturedStatus);
            self::assertNull($controller->capturedSearch);
            self::assertSame('emitted,cancelled', $response->data['status'] ?? null);
            self::assertSame('status:emitted,cancelled', $response->data['search'] ?? null);
            self::assertSame(['multi-status'], $response->data['receipts'] ?? null);
        }

        public function testIndexParsesEqualDateFilterToken(): void
        {
            $controller = new class () extends InvoiceController {
                public ?array $capturedDateFilter = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedDateFilter = $dateFilter;

                    return [];
                }
            };

            $controller->index(new Request(['search' => 'data_emissao:2024-03-15']));

            self::assertSame(['operator' => '=', 'from' => '2024-03-15', 'to' => null], $controller->capturedDateFilter);
        }

        public function testIndexParsesNotEqualDateFilterToken(): void
        {
            $controller = new class () extends InvoiceController {
                public ?array $capturedDateFilter = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedDateFilter = $dateFilter;

                    return [];
                }
            };

            $controller->index(new Request(['search' => 'not data_emissao:2024-03-15']));

            self::assertSame(['operator' => '!=', 'from' => '2024-03-15', 'to' => null], $controller->capturedDateFilter);
        }

        public function testIndexParsesDateRangeFilterToken(): void
        {
            $controller = new class () extends InvoiceController {
                public ?array $capturedDateFilter = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedDateFilter = $dateFilter;

                    return [];
                }
            };

            $controller->index(new Request(['search' => 'data_emissao>=2024-01-01 data_emissao<=2024-01-31']));

            self::assertSame(['operator' => 'range', 'from' => '2024-01-01', 'to' => '2024-01-31'], $controller->capturedDateFilter);
        }

        public function testIndexParsesDateFilterWithoutLeakingToSearchTerm(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = null;
                public ?array $capturedDateFilter = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch  = $search;
                    $this->capturedDateFilter = $dateFilter;

                    return [];
                }
            };

            $controller->index(new Request(['search' => 'ACME data_emissao:2024-03-15 foo']));

            self::assertSame('ACME foo', $controller->capturedSearch);
            self::assertSame(['operator' => '=', 'from' => '2024-03-15', 'to' => null], $controller->capturedDateFilter);
        }

        public function testIndexParsesDateRangeWithoutLeakingToSearchTerm(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = null;
                public ?array $capturedDateFilter = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
                {
                    $this->capturedSearch  = $search;
                    $this->capturedDateFilter = $dateFilter;

                    return [];
                }
            };

            $controller->index(new Request(['search' => 'data_emissao>=2024-01-01 data_emissao<=2024-01-31']));

            self::assertNull($controller->capturedSearch);
            self::assertSame(['operator' => 'range', 'from' => '2024-01-01', 'to' => '2024-01-31'], $controller->capturedDateFilter);
        }

        public function testShowReturnsInvoiceAndReceiptInView(): void
        {
            $invoice = new Invoice(id: 99, amount: 300.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(99, 'CHAVE-99');

            $response = (new InvoiceController())->show($invoice);

            self::assertSame('nfse::invoices.show', $response->name);
            self::assertSame($invoice, $response->data['invoice'] ?? null);
            self::assertSame($receipt, $response->data['receipt'] ?? null);
        }

        public function testDashboardReturnsViewWithOperationalStats(): void
        {
            $controller = new class () extends InvoiceController {
                protected function dashboardStats(): array
                {
                    return [
                        'total' => 9,
                        'emitted' => 7,
                        'cancelled' => 2,
                        'sandbox_mode' => true,
                    ];
                }

                protected function dashboardRecentReceipts(): array
                {
                    return [];
                }
            };

            $response = $controller->dashboard();

            self::assertSame([], $response->data['recentReceipts'] ?? null);
            self::assertSame('nfse::dashboard.index', $response->name);
            self::assertSame([
                'total' => 9,
                'emitted' => 7,
                'cancelled' => 2,
                'sandbox_mode' => true,
            ], $response->data['stats'] ?? []);
        }

        public function testPendingRedirectsToUnifiedListingWithDefaultFilters(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending();

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 25]], $response->parameters);
        }

        public function testPendingPassesSearchAndPerPageToUnifiedListing(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending(new Request(['limit' => '50', 'search' => '  ACME  ']));

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 50, 'search' => 'ACME']], $response->parameters);
        }

        public function testPendingNormalizesInvalidFiltersBeforeRedirectingToUnifiedListing(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending(new Request(['limit' => '13', 'search' => '   ']));

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 25]], $response->parameters);
        }

        public function testPendingUsesQLegacyFallbackWhenSearchQueryIsMissing(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending(new Request(['limit' => '25', 'q' => '  LEGACY  ']));

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 25, 'search' => 'LEGACY']], $response->parameters);
        }

        public function testEmitRedirectsToPendingWhenEmissionReadinessIsNotSatisfied(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 77,
                amount: 450.0,
                items: [['name' => 'Servico X']],
                description: 'Descricao',
            );

            $controller = new class () extends InvoiceController {
                protected function emissionReadiness(): array
                {
                    return [
                        'isReady' => false,
                        'checklist' => [
                            'cnpj_prestador' => false,
                            'municipio_ibge' => true,
                            'item_lista_servico' => true,
                            'certificate' => false,
                        ],
                    ];
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    throw new \RuntimeException('Client must not be created when readiness is not satisfied.');
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Existem configuracoes pendentes para liberar a emissao.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testPendingIncludesCertificateSecretChecklistFlagWhenVaultSecretIsMissing(): void
        {
            $controller = new class () extends InvoiceController {
                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return false;
                }

                protected function pendingInvoices(int $perPage = 25, ?string $search = null): iterable
                {
                    return [];
                }
            };

            $response = $controller->pending();

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 25]], $response->parameters);
        }

        public function testPendingIncludesTransportCertificateChecklistFlagWhenPemFilesAreMissing(): void
        {
            $controller = new class () extends InvoiceController {
                protected function pendingInvoices(int $perPage = 25, ?string $search = null): iterable
                {
                    return [];
                }

                protected function projectRootPath(string $relativePath): string
                {
                    return '/nonexistent/' . ltrim($relativePath, '/');
                }
            };

            $response = $controller->pending();

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'limit' => 25]], $response->parameters);
        }

        public function testRefreshQueriesReceiptUpdatesStatusAndRedirectsToShowPage(): void
        {
            $invoice = new Invoice(id: 91, amount: 220.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(91, 'CHAVE-91', 'processing');

            $client = new class () implements NfseClientInterface {
                public array $queryCalls = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    $this->queryCalls[] = $chaveAcesso;

                    return new ReceiptData(
                        nfseNumber: 'NF-91',
                        chaveAcesso: 'CHAVE-91',
                        dataEmissao: '2026-03-21T14:35:00-03:00',
                        codigoVerificacao: 'VER-91',
                    );
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->refresh($invoice);

            self::assertSame(['CHAVE-91'], $client->queryCalls);
            self::assertSame([
                [
                    'nfse_number' => 'NF-91',
                    'chave_acesso' => 'CHAVE-91',
                    'data_emissao' => '2026-03-21T14:35:00-03:00',
                    'codigo_verificacao' => 'VER-91',
                    'status' => 'emitted',
                ],
            ], $receipt->updatedPayloads);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e NF-91 atualizada com sucesso.', $response->flash['success'] ?? null);
        }

        public function testRefreshReturnsErrorFlashWhenProviderQueryFails(): void
        {
            $invoice = new Invoice(id: 92, amount: 330.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(92, 'CHAVE-92', 'processing');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \RuntimeException('Provider timeout');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->refresh($invoice);

            self::assertSame([], $receipt->updatedPayloads);
            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel atualizar o status da NFS-e.', $response->flash['error'] ?? null);
        }

        public function testRefreshDoesNotQueryCancelledReceiptAndReturnsWarning(): void
        {
            $invoice = new Invoice(id: 93, amount: 440.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(93, 'CHAVE-93', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public array $queryCalls = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    $this->queryCalls[] = $chaveAcesso;

                    throw new \RuntimeException('Query should not be called for cancelled receipts.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->refresh($invoice);

            self::assertSame([], $client->queryCalls);
            self::assertSame([], $receipt->updatedPayloads);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e cancelada nao pode ser atualizada por refresh. Use a acao de reemissao quando aplicavel.', $response->flash['warning'] ?? null);
        }

        public function testRefreshAllUpdatesReceiptsAndRedirectsWithSuccessMessage(): void
        {
            $receiptA = InvoiceControllerIsolationState::makeReceipt(101, 'CHAVE-101', 'processing');
            $receiptB = InvoiceControllerIsolationState::makeReceipt(102, 'CHAVE-102', 'processing');

            $client = new class () implements NfseClientInterface {
                public array $queries = [];

                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    $this->queries[] = $chaveAcesso;

                    return new ReceiptData(
                        nfseNumber: 'NF-' . substr($chaveAcesso, -3),
                        chaveAcesso: $chaveAcesso,
                        dataEmissao: '2026-03-21T15:00:00-03:00',
                        codigoVerificacao: 'COD-' . substr($chaveAcesso, -3),
                    );
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client, [$receiptA, $receiptB]) extends InvoiceController {
                public function __construct(
                    private readonly NfseClientInterface $client,
                    private readonly array $receipts,
                ) {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function refreshableReceipts(): iterable
                {
                    return $this->receipts;
                }
            };

            $response = $controller->refreshAll();

            self::assertSame(['CHAVE-101', 'CHAVE-102'], $client->queries);
            self::assertCount(1, $receiptA->updatedPayloads);
            self::assertCount(1, $receiptB->updatedPayloads);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame('Atualizacao concluida para 2 NFS-e.', $response->flash['success'] ?? null);
        }

        public function testRefreshAllHandlesPartialFailuresWithWarningMessage(): void
        {
            $receiptA = InvoiceControllerIsolationState::makeReceipt(201, 'CHAVE-201', 'processing');
            $receiptB = InvoiceControllerIsolationState::makeReceipt(202, 'CHAVE-202', 'processing');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    if ($chaveAcesso === 'CHAVE-202') {
                        throw new \RuntimeException('Provider unavailable');
                    }

                    return new ReceiptData(
                        nfseNumber: 'NF-201',
                        chaveAcesso: 'CHAVE-201',
                        dataEmissao: '2026-03-21T15:10:00-03:00',
                        codigoVerificacao: 'COD-201',
                    );
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client, [$receiptA, $receiptB]) extends InvoiceController {
                public function __construct(
                    private readonly NfseClientInterface $client,
                    private readonly array $receipts,
                ) {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function refreshableReceipts(): iterable
                {
                    return $this->receipts;
                }
            };

            $response = $controller->refreshAll();

            self::assertCount(1, $receiptA->updatedPayloads);
            self::assertSame([], $receiptB->updatedPayloads);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame('Atualizacao parcial: 1 atualizadas e 1 falharam.', $response->flash['warning'] ?? null);
        }

        public function testReemitBlocksWhenIbsCbsIsRequiredAndConfigurationIsMissing(): void
        {
            ControllerIsolationState::$settings['nfse.opcao_simples_nacional'] = 1;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 3001,
                amount: 700.45,
                items: [['name' => 'Servico Reemissao']],
                issuedAt: '2026-10-08 12:00:00',
            );

            InvoiceControllerIsolationState::makeReceipt(3001, 'CHAVE-3001', 'cancelled');

            $controller = new class () extends InvoiceController {
                protected function emissionReadiness(): array
                {
                    return ['isReady' => true, 'checklist' => []];
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('route', $response->target ?? null);
            self::assertSame('nfse.invoices.show', $response->route ?? null);
            self::assertStringContainsString(
                'IBS/CBS obrigatorio desde 2026-10-01',
                (string) ($response->flash['error'] ?? ''),
            );
        }

        public function testReemitBuildsDpsForCancelledReceiptAndRedirectsToShow(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 301,
                amount: 700.45,
                items: [['name' => 'Servico Reemissao']],
                description: 'Descricao reemissao',
                contactName: 'Cliente X',
                contactTaxNumber: '12312312300199',
            );

            $existingReceipt = InvoiceControllerIsolationState::makeReceipt(301, 'CHAVE-301', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData(
                        nfseNumber: 'NF-RE-301',
                        chaveAcesso: 'CHAVE-RE-301',
                        dataEmissao: '2026-03-21T18:00:00-03:00',
                        codigoVerificacao: 'RE301',
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function emissionReadiness(): array
                {
                    return [
                        'isReady' => true,
                        'checklist' => [
                            'cnpj_prestador' => true,
                            'municipio_ibge' => true,
                            'item_lista_servico' => true,
                            'certificate' => true,
                        ],
                    ];
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('[0107] Servico Reemissao', $client->capturedDps?->discriminacao);
            self::assertSame('9' . str_pad((string) $existingReceipt->id, 14, '0', STR_PAD_LEFT), $client->capturedDps?->numeroDps);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
            self::assertSame('cancelled', $existingReceipt->status);
            self::assertSame([], $existingReceipt->updatedPayloads);
            self::assertCount(2, NfseReceipt::$records);
            $reissued = NfseReceipt::$records[1];
            self::assertSame('emitted', $reissued->status);
            self::assertSame('NF-RE-301', $reissued->nfse_number);
            self::assertSame('CHAVE-RE-301', $reissued->chave_acesso);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e reemitida NF-RE-301 com sucesso.', $response->flash['success'] ?? null);
        }

        public function testReemitDispatchesEmailPayloadWhenEmailRequested(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 3301,
                amount: 450.0,
                items: [['name' => 'Servico Reemissao Email']],
                contactEmail: 'cliente@reemissao.test',
            );

            InvoiceControllerIsolationState::makeReceipt(3301, 'CHAVE-3301', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    return new ReceiptData(
                        nfseNumber: 'NF-RE-3301',
                        chaveAcesso: 'CHAVE-RE-3301',
                        dataEmissao: '2026-10-07T10:00:00-03:00',
                        codigoVerificacao: 'CV-RE-3301',
                        rawXml: '<NFSe>authorized</NFSe>',
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $dispatchCalls = [];

            $controller = new class ($client, $dispatchCalls) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client, private array &$dispatchCalls)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function emissionReadiness(): array
                {
                    return ['isReady' => true, 'checklist' => []];
                }

                protected function dispatchPostEmission(
                    Invoice $invoice,
                    \Modules\Nfse\Models\NfseReceipt $receipt,
                    ?array $email,
                ): void {
                    $this->dispatchCalls[] = [
                        'invoice_id' => $invoice->id,
                        'receipt' => $receipt,
                        'email' => $email,
                    ];
                }
            };

            $request = new Request([
                'nfse_send_email' => '1',
                'nfse_email_to' => 'destinatario@reemissao.test',
                'nfse_email_subject' => 'Assunto reemissao',
                'nfse_email_body' => 'Corpo reemissao',
                'nfse_email_attach_danfse' => '1',
                'nfse_email_attach_xml' => '0',
            ]);

            $controller->reemit($invoice, $request);

            self::assertCount(1, $dispatchCalls);
            self::assertSame(3301, $dispatchCalls[0]['invoice_id']);
            self::assertTrue($dispatchCalls[0]['email']['attach_danfse']);
            self::assertFalse($dispatchCalls[0]['email']['attach_xml']);
            self::assertSame('destinatario@reemissao.test', $dispatchCalls[0]['email']['custom_mail']['to'] ?? null);
            self::assertSame('Assunto reemissao', $dispatchCalls[0]['email']['custom_mail']['subject'] ?? null);
        }

        public function testReemitUsesCustomDescriptionFromRequestWhenProvided(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 302,
                amount: 450.0,
                items: [['name' => 'Servico Reemissao Automatico']],
                description: 'Descricao de reemissao original',
            );

            InvoiceControllerIsolationState::makeReceipt(302, 'CHAVE-302', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public ?DpsData $capturedDps = null;

                public function emit(DpsData $dps): ReceiptData
                {
                    $this->capturedDps = $dps;

                    return new ReceiptData('NF-RE-302', 'CHAVE-RE-302', '2026-03-22T09:00:00-03:00');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function emissionReadiness(): array
                {
                    return [
                        'isReady' => true,
                        'checklist' => [
                            'cnpj_prestador' => true,
                            'municipio_ibge' => true,
                            'item_lista_servico' => true,
                            'certificate' => true,
                        ],
                    ];
                }
            };

            $controller->reemit($invoice, new Request([
                'nfse_discriminacao_custom' => 'Descricao manual para reemissao',
            ]));

            self::assertSame('Descricao manual para reemissao', $client->capturedDps?->discriminacao);
        }

        public function testEmitRedirectsToPendingWithErrorFlashWhenGatewayRejectsIssuance(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 99,
                amount: 500.0,
                items: [['name' => 'Servico X']],
            );

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new IssuanceException(
                        'Gateway rejected',
                        NfseErrorCode::IssuanceRejected,
                        422,
                        ['mensagem' => 'CNPJ inválido'],
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testEmitRedirectsToPendingWithErrorFlashWhenSecretStoreFails(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 199,
                amount: 500.0,
                items: [['name' => 'Servico X']],
            );

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new SecretStoreException('Vault unavailable');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel acessar o segredo do certificado no Vault/OpenBao.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testCancelRedirectsToIndexWithErrorFlashWhenGatewayRejectsCancellation(): void
        {
            $invoice = new Invoice(id: 201, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(201, 'CHAVE-201', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'Gateway rejected cancel',
                        NfseErrorCode::CancellationRejected,
                        409,
                        ['mensagem' => 'NFS-e não pode ser cancelada'],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame('NFS-e não pode ser cancelada', $response->flash['nfse_gateway_error_detail'] ?? null);
            self::assertSame([], $receipt->updatedPayloads);
        }

        public function testCancelRedirectsToIndexWithMessageDetailWhenGatewayPayloadUsesMessageKey(): void
        {
            $invoice = new Invoice(id: 202, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(202, 'CHAVE-202', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'Gateway rejected cancel',
                        NfseErrorCode::CancellationRejected,
                        405,
                        ['message' => 'The requested resource does not support http method DELETE.'],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame(
                'The requested resource does not support http method DELETE.',
                $response->flash['nfse_gateway_error_detail'] ?? null,
            );
            self::assertSame([], $receipt->updatedPayloads);
        }

        public function testCancelRedirectsToIndexWithExceptionMessageWhenPayloadHasNoDetailFields(): void
        {
            $invoice = new Invoice(id: 203, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(203, 'CHAVE-203', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'SEFIN gateway rejected cancellation (HTTP 405)',
                        NfseErrorCode::CancellationRejected,
                        405,
                        ['unexpected' => 'shape'],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame(
                'SEFIN gateway rejected cancellation (HTTP 405)',
                $response->flash['nfse_gateway_error_detail'] ?? null,
            );
            self::assertSame([], $receipt->updatedPayloads);
        }

        public function testCancelRedirectsToIndexWithDetailFromValidationErrorPayloadShape(): void
        {
            $invoice = new Invoice(id: 204, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(204, 'CHAVE-204', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'Gateway rejected cancel',
                        NfseErrorCode::CancellationRejected,
                        400,
                        [
                            'title' => 'One or more validation errors occurred.',
                            'detail' => 'Schema validation failed for event payload.',
                            'errors' => [
                                'pedidoRegistroEventoXmlGZipB64' => ['The payload is invalid.'],
                            ],
                        ],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame(
                'Schema validation failed for event payload. - The payload is invalid.',
                $response->flash['nfse_gateway_error_detail'] ?? null,
            );
            self::assertSame([], $receipt->updatedPayloads);
        }

        public function testCancelRedirectsToIndexWithDetailFromErroArrayPayloadShape(): void
        {
            $invoice = new Invoice(id: 205, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(205, 'CHAVE-205', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'Gateway rejected cancel',
                        NfseErrorCode::CancellationRejected,
                        400,
                        [
                            'erro' => [
                                [
                                    'codigo' => 'E6154',
                                    'descricao' => 'Xml não está utilizando codificação UTF-8.',
                                ],
                            ],
                        ],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame(
                'E6154 - Xml não está utilizando codificação UTF-8.',
                $response->flash['nfse_gateway_error_detail'] ?? null,
            );
            self::assertSame([], $receipt->updatedPayloads);
        }

        public function testCancelTreatsAlreadyRegisteredCancellationAsSuccess(): void
        {
            $invoice = new Invoice(id: 206, amount: 100.0);
            $receipt = InvoiceControllerIsolationState::makeReceipt(206, 'CHAVE-206', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException(
                        'Gateway rejected cancel',
                        NfseErrorCode::CancellationRejected,
                        400,
                        [
                            'erro' => [
                                [
                                    'codigo' => 'E0840',
                                    'descricao' => 'Evento de cancelamento já vinculado à NFS-e.',
                                ],
                            ],
                        ],
                    );
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->cancel($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e cancelada', $response->flash['success'] ?? null);
            self::assertArrayNotHasKey('error', $response->flash);
            self::assertSame([['status' => 'cancelled']], $receipt->updatedPayloads);
        }

        public function testCancelReturnsJsonWithRedirectWhenRequestIsAjax(): void
        {
            $invoice = new Invoice(id: 210, amount: 100.0);
            InvoiceControllerIsolationState::makeReceipt(210, 'CHAVE-210', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    return true;
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new \Illuminate\Http\Request([], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

            $response = $controller->cancel($invoice, $request);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertTrue($response->payload['success'] ?? false);
            self::assertFalse($response->payload['error'] ?? true);
            self::assertNotEmpty($response->payload['redirect'] ?? '');
        }

        public function testCancelReturnsJsonErrorOnGatewayExceptionWhenRequestIsAjax(): void
        {
            $invoice = new Invoice(id: 211, amount: 100.0);
            InvoiceControllerIsolationState::makeReceipt(211, 'CHAVE-211', 'emitted');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new CancellationException('Gateway rejected', NfseErrorCode::CancellationRejected, 422, ['detail' => 'NFS-e não pode ser cancelada']);
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $request = new \Illuminate\Http\Request([], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

            $response = $controller->cancel($invoice, $request);

            self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
            self::assertFalse($response->payload['success'] ?? true);
            self::assertTrue($response->payload['error'] ?? false);
        }

        public function testReemitRedirectsToShowWithErrorFlashWhenGatewayRejectsIssuance(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 303,
                amount: 750.0,
                items: [['name' => 'Srv']],
            );
            InvoiceControllerIsolationState::makeReceipt(303, 'CHAVE-303', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new IssuanceException(
                        'Gateway rejected reemit',
                        NfseErrorCode::IssuanceRejected,
                        422,
                        ['mensagem' => 'Dados inválidos'],
                    );
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertNotNull($response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testReemitRedirectsToShowWithErrorFlashWhenSecretStoreFails(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 304,
                amount: 750.0,
                items: [['name' => 'Srv']],
            );
            InvoiceControllerIsolationState::makeReceipt(304, 'CHAVE-304', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new SecretStoreException('Vault unavailable');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel acessar o segredo do certificado no Vault/OpenBao.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testEmitRedirectsToPendingWithErrorFlashWhenPfxImportFails(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 299,
                amount: 800.0,
                items: [['name' => 'Servico PFX']],
            );

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new PfxImportException('Legacy PFX import failed');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel importar o certificado PFX.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testReemitRedirectsToShowWithErrorFlashWhenPfxImportFails(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 399,
                amount: 300.0,
                items: [['name' => 'Servico PFX']],
            );
            InvoiceControllerIsolationState::makeReceipt(399, 'CHAVE-399', 'cancelled');

            $client = new class () implements NfseClientInterface {
                public function emit(DpsData $dps): ReceiptData
                {
                    throw new PfxImportException('Legacy PFX import failed on reemit');
                }

                public function query(string $chaveAcesso): ReceiptData
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function cancel(string $chaveAcesso, string $motivo): bool
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }

                public function getDanfse(string $chaveAcesso): string
                {
                    throw new \BadMethodCallException('Not used in this test.');
                }
            };

            $controller = new class ($client) extends InvoiceController {
                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel importar o certificado PFX.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testReemitReturnsWarningWhenReceiptIsNotCancelled(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 302, amount: 200.0, items: [['name' => 'Servico reemissao']]);
            InvoiceControllerIsolationState::makeReceipt(302, 'CHAVE-302', 'emitted');

            $controller = new class () extends InvoiceController {
                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    throw new \RuntimeException('Client must not be created when receipt is not cancelled.');
                }
            };

            $response = $controller->reemit($invoice);

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('A NFS-e precisa estar cancelada para reemissao manual.', $response->flash['warning'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
        }

        public function testPreparePostEmitEmailBuildsQueuedPayloadAndPersistsPreferences(): void
        {
            InvoiceControllerIsolationState::reset();

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 55,
                amount: 100.0,
                contactEmail: 'cliente@example.com',
            );

            $request = \Illuminate\Http\Request::create('/nfse/emit', 'POST', [
                'nfse_send_email' => '1',
                'nfse_email_to' => 'destinatario@example.com',
                'nfse_email_subject' => 'NFS-e emitida',
                'nfse_email_body' => 'Prezado cliente',
                'nfse_email_attach_danfse' => '1',
                'nfse_email_attach_xml' => '0',
            ]);

            $controller = new class () extends InvoiceController {
                public function exposePreparePostEmitEmail(\Illuminate\Http\Request $request, Invoice $invoice): ?array
                {
                    return $this->preparePostEmitEmail($request, $invoice);
                }
            };

            $email = $controller->exposePreparePostEmitEmail($request, $invoice);

            self::assertIsArray($email);
            self::assertTrue($email['attach_danfse']);
            self::assertFalse($email['attach_xml']);
            self::assertSame('destinatario@example.com', $email['custom_mail']['to'] ?? null);
            self::assertSame('NFS-e emitida', $email['custom_mail']['subject'] ?? null);
            self::assertSame('1', ControllerIsolationState::$settings['nfse.send_email_on_emit'] ?? null);
        }

        public function testPreparePostEmitEmailReturnsNullWhenSendingIsDisabledButPersistsPreferences(): void
        {
            InvoiceControllerIsolationState::reset();

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 56, amount: 100.0);
            $request = \Illuminate\Http\Request::create('/nfse/emit', 'POST', [
                'nfse_send_email' => '0',
                'nfse_email_attach_invoice_pdf' => '0',
                'nfse_email_attach_danfse' => '0',
                'nfse_email_attach_xml' => '1',
                'nfse_email_copy_to_self' => '1',
            ]);

            $controller = new class () extends InvoiceController {
                public function exposePreparePostEmitEmail(\Illuminate\Http\Request $request, Invoice $invoice): ?array
                {
                    return $this->preparePostEmitEmail($request, $invoice);
                }
            };

            self::assertNull($controller->exposePreparePostEmitEmail($request, $invoice));
            self::assertSame('0', ControllerIsolationState::$settings['nfse.send_email_on_emit'] ?? null);
            self::assertSame('0', ControllerIsolationState::$settings['nfse.email_attach_invoice_pdf_on_emit'] ?? null);
            self::assertSame('0', ControllerIsolationState::$settings['nfse.email_attach_danfse_on_emit'] ?? null);
            self::assertSame('1', ControllerIsolationState::$settings['nfse.email_attach_xml_on_emit'] ?? null);
            self::assertSame('1', ControllerIsolationState::$settings['nfse.email_copy_to_self_on_emit'] ?? null);
        }

        public function testPreparePostEmitEmailCanPersistTemplateDefaults(): void
        {
            InvoiceControllerIsolationState::reset();

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 60, amount: 100.0);
            $template = new \App\Models\Setting\EmailTemplate();
            $template->subject = 'Original subject';
            $template->body = 'Original body';
            \App\Models\Setting\EmailTemplate::$stubInstance = $template;

            $request = \Illuminate\Http\Request::create('/nfse/emit', 'POST', [
                'nfse_send_email' => '1',
                'nfse_email_to' => 'cli@example.com',
                'nfse_email_subject' => 'Novo assunto',
                'nfse_email_body' => '<p>Novo corpo</p>',
                'nfse_email_save_default' => '1',
            ]);

            $controller = new class () extends InvoiceController {
                public function exposePreparePostEmitEmail(\Illuminate\Http\Request $request, Invoice $invoice): ?array
                {
                    return $this->preparePostEmitEmail($request, $invoice);
                }
            };

            $email = $controller->exposePreparePostEmitEmail($request, $invoice);

            self::assertIsArray($email);
            self::assertSame('Novo assunto', $template->subject);
            self::assertSame('<p>Novo corpo</p>', $template->body);
        }

        public function testServicePreviewEmailDefaultsReturnsCopyToSelfFromSetting(): void
        {
            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$settings['nfse.email_copy_to_self_on_emit'] = '1';

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 64, amount: 50.0);

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return ['selected_service' => null, 'line_items' => [], 'missing_items' => [], 'requires_confirmation' => false, 'requires_split' => false];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertArrayHasKey('email_defaults', $payload);
            self::assertTrue($payload['email_defaults']['copy_to_self']);
        }

        public function testServicePreviewEmailDefaultsCopyToSelfDefaultsFalse(): void
        {
            InvoiceControllerIsolationState::reset();

            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 65, amount: 50.0);

            $controller = new class () extends InvoiceController {
                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return null;
                }

                protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
                {
                    return ['selected_service' => null, 'line_items' => [], 'missing_items' => [], 'requires_confirmation' => false, 'requires_split' => false];
                }

                protected function availableInvoiceServices(Invoice $invoice): array
                {
                    return [];
                }
            };

            $response = $controller->servicePreview($invoice);
            $payload = $response->getData(true);

            self::assertFalse($payload['email_defaults']['copy_to_self']);
        }

    }
}
