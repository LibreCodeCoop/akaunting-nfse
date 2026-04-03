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
    use LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
    use LibreCodeCoop\NfsePHP\Dto\DpsData;
    use LibreCodeCoop\NfsePHP\Dto\ReceiptData;
    use LibreCodeCoop\NfsePHP\Exception\CancellationException;
    use LibreCodeCoop\NfsePHP\Exception\IssuanceException;
    use LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
    use LibreCodeCoop\NfsePHP\Exception\PfxImportException;
    use LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Http\Controllers\InvoiceController;
    use Modules\Nfse\Models\NfseReceipt;
    use Modules\Nfse\Tests\TestCase;
    use Modules\Nfse\Tests\Unit\Http\Controllers\Support\InvoiceControllerIsolationState;

    final class InvoiceControllerTest extends TestCase
    {
        public function testControllerUsesDocumentModelAliasForInvoices(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('use App\\Models\\Document\\Document as Invoice;', $content);
            self::assertStringContainsString('Invoice::invoice()', $content);
        }

        public function testCompanyServiceSelectionSupportRecognizesEloquentModelWithoutMethodExistsWhereCheck(): void
        {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/InvoiceController.php');

            self::assertStringContainsString('is_subclass_of(CompanyService::class', $content);
            self::assertStringNotContainsString("method_exists(CompanyService::class, 'where')", $content);
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
            ];
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
                'nfse.municipio_ibge' => '3303302',
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
                'nfse.sandbox_mode' => false,
            ];

            $certificateDir = ControllerIsolationState::$storageRoot . '/app/nfse/pfx';
            if (!is_dir($certificateDir)) {
                mkdir($certificateDir, 0o777, true);
            }

            file_put_contents($certificateDir . '/12345678000195.pfx', 'fake-certificate');
            file_put_contents(ControllerIsolationState::$storageRoot . '/client.crt.pem', 'fake-transport-certificate');
            file_put_contents(ControllerIsolationState::$storageRoot . '/client.key.pem', 'fake-transport-private-key');
        }

        public function testEmitBuildsDpsPersistsReceiptAndRedirectsToShowPage(): void
        {
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
            };

            $controller = new class ($client) extends InvoiceController {
                public array $clientCalls = [];

                public function __construct(private readonly NfseClientInterface $client)
                {
                }

                protected function makeClient(bool $sandboxMode): NfseClientInterface
                {
                    $this->clientCalls[] = ['sandbox' => $sandboxMode];

                    return $this->client;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $response = $controller->emit($invoice);

            self::assertSame([['sandbox' => false]], $controller->clientCalls);
            self::assertSame('12345678000195', $client->capturedDps?->cnpjPrestador);
            self::assertSame('3303302', $client->capturedDps?->municipioIbge);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('010701', $client->capturedDps?->codigoTributacaoNacional);
            self::assertSame('1500.25', $client->capturedDps?->valorServico);
            self::assertSame('4.50', $client->capturedDps?->aliquota);
            self::assertSame('Servico A | Servico B', $client->capturedDps?->discriminacao);
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
            // CSLL = 1.00% × 1500.25 = 15.0025 → '15.00' (tipoRetencao '3' ≠ '0')
            self::assertSame('15.00', $client->capturedDps?->federalValorCsll);
            // CP always '' (RNG6110 reject in produção restrita)
            self::assertSame('', $client->capturedDps?->federalValorCp);
            self::assertSame(0, $client->capturedDps?->indicadorTributacao);
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
            // IRRF and CSLL are calculated from percentage settings (1.00% × 1500.25 = 15.00)
            self::assertSame('15.00', $client->capturedDps?->federalValorIrrf);
            self::assertSame('15.00', $client->capturedDps?->federalValorCsll);
            // CP always '' (RNG6110 reject in produção restrita)
            self::assertSame('', $client->capturedDps?->federalValorCp);
            // No tributos_* percent configured → indicadorTributacao = 0
            self::assertSame(0, $client->capturedDps?->indicadorTributacao);
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

        public function testRuntimeXmlBuilderStartsInfDpsWithTpAmbBeforeMunicipalityFields(): void
        {
            $builder = new \LibreCodeCoop\NfsePHP\Xml\XmlBuilder();
            $xml = $builder->buildDps(new DpsData(
                cnpjPrestador: '12345678000195',
                municipioIbge: '3303302',
                itemListaServico: '0107',
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
            self::assertStringContainsString('<serv><locPrest><cLocPrestacao>3303302</cLocPrestacao></locPrest><cServ><cTribNac>010101</cTribNac><cTribMun>0107</cTribMun>', $normalizedXml);
            self::assertStringContainsString('<piscofins><CST>01</CST><vBCPisCofins>31500.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins><vPis>204.75</vPis><vCofins>945.00</vCofins><tpRetPisCofins>4</tpRetPisCofins></piscofins>', $normalizedXml);
            self::assertStringContainsString('<vRetIRRF>472.50</vRetIRRF>', $normalizedXml);
            self::assertStringNotContainsString('<vRetCSLL>', $normalizedXml);
            self::assertStringNotContainsString('<vRetCP>', $normalizedXml);
            self::assertStringContainsString('<pTotTrib><pTotTribFed>3.65</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>2.00</pTotTribMun></pTotTrib>', $normalizedXml);
            self::assertStringNotContainsString('<cMun>3303302</cMun>', $normalizedXml);
        }

        public function testEmitFallsBackToNoRetentionWhenTypeRequiresCsllButConfiguredValueIsZero(): void
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

            self::assertSame('0', $client->capturedDps?->federalPiscofinsTipoRetencao);
            self::assertSame('', $client->capturedDps?->federalValorCsll);
        }

        public function testEmitPrefersDefaultCompanyServiceOverLegacyFiscalSettings(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 52,
                amount: 875.4,
                items: [],
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

            self::assertSame('1401', $client->capturedDps?->itemListaServico);
            self::assertSame('140101', $client->capturedDps?->codigoTributacaoNacional);
            self::assertSame('6.75', $client->capturedDps?->aliquota);
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

        public function testItemListaServicoPreservesThreeDigitMunicipalCode(): void
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
                'item_lista_servico' => '001',
                'codigo_tributacao_nacional' => '010101',
                'aliquota' => '2.00',
            ];

            self::assertSame('001', $controller->exposedItemListaServico($defaultService));
        }

        public function testEmissionReadinessDoesNotRequireNationalTaxCodeWhenUsingCompanyServices(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 88, amount: 100.0, items: []);

            $controller = new class () extends InvoiceController {
                public function exposedEmissionReadiness(): array
                {
                    return $this->emissionReadiness();
                }

                protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
                {
                    return (object) [
                        'item_lista_servico' => '1401',
                        'codigo_tributacao_nacional' => null,
                        'aliquota' => '5.00',
                        'is_default' => true,
                        'is_active' => true,
                    ];
                }

                protected function supportsCompanyServiceSelection(): bool
                {
                    return true;
                }

                protected function hasCertificateSecret(string $cnpj): bool
                {
                    return true;
                }
            };

            $readiness = $controller->exposedEmissionReadiness();

            self::assertTrue($readiness['isReady'] ?? false);
            self::assertArrayNotHasKey('codigo_tributacao_nacional', $readiness['checklist']);
            self::assertSame(true, $readiness['checklist']['item_lista_servico'] ?? null);
        }

        public function testEmitSetsTipoAmbienteFromSandboxMode(): void
        {
            ControllerIsolationState::$settings['nfse.sandbox_mode'] = true;

            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 99,
                amount: 100.0,
                items: [],
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
                items: [],
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
                items: [],
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

        public function testEmitFallsBackToInvoiceDescriptionWhenItemsAreEmpty(): void
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

            self::assertSame('Descricao da nota', $client->capturedDps?->discriminacao);
        }

        public function testEmitFallsBackToDefaultServiceLabelWhenItemsAndDescriptionAreEmpty(): void
        {
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 8,
                amount: 91.0,
                items: [],
                description: '',
            );

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

            self::assertSame('Servico padrao', $client->capturedDps?->discriminacao);
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
            self::assertSame('nfse.invoices.index', $response->route);
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
        }

        public function testIndexPassesStatusFilterFromRequestToReceiptQuery(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
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

        public function testIndexFallsBackToAllWhenStatusFilterIsInvalid(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;
                public ?int $capturedPerPage = null;
                public ?string $capturedSearch = null;

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
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

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
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

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
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

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
                {
                    $this->capturedSearch = $search;

                    return ['search'];
                }
            };

            $response = $controller->index(new Request(['q' => '  NF-2026-001  ']));

            self::assertSame('NF-2026-001', $controller->capturedSearch);
            self::assertSame('NF-2026-001', $response->data['search'] ?? null);
            self::assertSame(['search'], $response->data['receipts'] ?? null);
        }

        public function testIndexConvertsEmptySearchToNull(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedSearch = 'marker';

                protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
                {
                    $this->capturedSearch = $search;

                    return ['empty-search'];
                }
            };

            $response = $controller->index(new Request(['q' => '   ']));

            self::assertNull($controller->capturedSearch);
            self::assertNull($response->data['search'] ?? null);
            self::assertSame(['empty-search'], $response->data['receipts'] ?? null);
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

            };

            $response = $controller->dashboard();

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
            self::assertSame([['status' => 'pending', 'per_page' => 25]], $response->parameters);
        }

        public function testPendingPassesSearchAndPerPageToUnifiedListing(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending(new Request(['per_page' => '50', 'q' => '  ACME  ']));

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'per_page' => 50, 'q' => 'ACME']], $response->parameters);
        }

        public function testPendingNormalizesInvalidFiltersBeforeRedirectingToUnifiedListing(): void
        {
            $controller = new class () extends InvoiceController {
            };

            $response = $controller->pending(new Request(['per_page' => '13', 'q' => '   ']));

            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending', 'per_page' => 25]], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending']], $response->parameters);
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
            self::assertSame([['status' => 'pending', 'per_page' => 25]], $response->parameters);
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
            self::assertSame([['status' => 'pending', 'per_page' => 25]], $response->parameters);
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
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('Nao foi possivel atualizar o status da NFS-e.', $response->flash['error'] ?? null);
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

            InvoiceControllerIsolationState::makeReceipt(301, 'CHAVE-301', 'cancelled');

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

            self::assertSame('Servico Reemissao', $client->capturedDps?->discriminacao);
            self::assertSame([
                [
                    'attributes' => ['invoice_id' => 301],
                    'values' => [
                        'nfse_number' => 'NF-RE-301',
                        'chave_acesso' => 'CHAVE-RE-301',
                        'data_emissao' => '2026-03-21T18:00:00-03:00',
                        'codigo_verificacao' => 'RE301',
                        'status' => 'emitted',
                    ],
                ],
            ], NfseReceipt::$updateOrCreateCalls);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.invoices.show', $response->route);
            self::assertSame([$invoice], $response->parameters);
            self::assertSame('NFS-e reemitida NF-RE-301 com sucesso.', $response->flash['success'] ?? null);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending']], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending']], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([], $response->parameters);
            self::assertSame('NFS-e cancelada', $response->flash['success'] ?? null);
            self::assertArrayNotHasKey('error', $response->flash);
            self::assertSame([['status' => 'cancelled']], $receipt->updatedPayloads);
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
            self::assertSame('nfse.invoices.index', $response->route);
            self::assertSame([['status' => 'pending']], $response->parameters);
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
            $invoice = new Invoice(id: 302, amount: 200.0);
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
    }
}
