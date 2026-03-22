<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/InvoiceControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use App\Models\Sale\Invoice;
    use LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
    use LibreCodeCoop\NfsePHP\Dto\DpsData;
    use LibreCodeCoop\NfsePHP\Dto\ReceiptData;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Http\Controllers\InvoiceController;
    use Modules\Nfse\Models\NfseReceipt;
    use Modules\Nfse\Tests\TestCase;
    use Modules\Nfse\Tests\Unit\Http\Controllers\Support\InvoiceControllerIsolationState;

    final class InvoiceControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$translations = [
                'nfse::general.nfse_emitted' => 'NFS-e emitida :number',
                'nfse::general.nfse_cancelled' => 'NFS-e cancelada',
                'nfse::general.cancel_motivo_default' => 'Cancelamento padrao',
            ];
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
                'nfse.municipio_ibge' => '3303302',
                'nfse.item_lista_servico' => '0107',
                'nfse.aliquota' => '4.50',
                'nfse.sandbox_mode' => false,
            ];
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
            );

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
            };

            $response = $controller->emit($invoice);

            self::assertSame([['sandbox' => false]], $controller->clientCalls);
            self::assertSame('12345678000195', $client->capturedDps?->cnpjPrestador);
            self::assertSame('3303302', $client->capturedDps?->municipioIbge);
            self::assertSame('0107', $client->capturedDps?->itemListaServico);
            self::assertSame('1500.25', $client->capturedDps?->valorServico);
            self::assertSame('4.50', $client->capturedDps?->aliquota);
            self::assertSame('Servico A | Servico B', $client->capturedDps?->discriminacao);
            self::assertSame('99887766000155', $client->capturedDps?->documentoTomador);
            self::assertSame('ACME Ltda', $client->capturedDps?->nomeTomador);
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
            };

            $controller->emit($invoice);

            self::assertSame('Descricao da nota', $client->capturedDps?->discriminacao);
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
    }
}
