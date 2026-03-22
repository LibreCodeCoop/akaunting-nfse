<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/InvoiceControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use App\Models\Sale\Invoice;
    use Illuminate\Http\Request;
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
                'nfse::general.nfse_refreshed' => 'NFS-e :number atualizada com sucesso.',
                'nfse::general.nfse_refresh_failed' => 'Nao foi possivel atualizar o status da NFS-e.',
                'nfse::general.nfse_refresh_all_done' => 'Atualizacao concluida para :count NFS-e.',
                'nfse::general.nfse_refresh_all_partial' => 'Atualizacao parcial: :updated atualizadas e :failed falharam.',
                'nfse::general.cancel_motivo_default' => 'Cancelamento padrao',
                'nfse::general.service_default' => 'Servico padrao',
                'nfse::general.invoices.emit_blocked_not_ready' => 'Ambiente nao esta pronto para emissao.',
            ];
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
                'nfse.municipio_ibge' => '3303302',
                'nfse.item_lista_servico' => '0107',
                'nfse.aliquota' => '4.50',
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
        }

        public function testIndexPassesStatusFilterFromRequestToReceiptQuery(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;

                protected function receiptsForIndex(string $status): mixed
                {
                    $this->capturedStatus = $status;

                    return ['filtered'];
                }
            };

            $response = $controller->index(new Request(['status' => 'cancelled']));

            self::assertSame('cancelled', $controller->capturedStatus);
            self::assertSame('cancelled', $response->data['status'] ?? null);
            self::assertSame(['filtered'], $response->data['receipts'] ?? null);
        }

        public function testIndexFallsBackToAllWhenStatusFilterIsInvalid(): void
        {
            $controller = new class () extends InvoiceController {
                public ?string $capturedStatus = null;

                protected function receiptsForIndex(string $status): mixed
                {
                    $this->capturedStatus = $status;

                    return ['fallback'];
                }
            };

            $response = $controller->index(new Request(['status' => 'invalid-status']));

            self::assertSame('all', $controller->capturedStatus);
            self::assertSame('all', $response->data['status'] ?? null);
            self::assertSame(['fallback'], $response->data['receipts'] ?? null);
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

        public function testDashboardReturnsViewWithOperationalStatsAndRecentReceipts(): void
        {
            $recentReceipts = [
                (object) ['invoice_id' => 10, 'nfse_number' => 'NF-10'],
            ];

            $controller = new class ($recentReceipts) extends InvoiceController {
                public function __construct(private readonly array $recentReceipts)
                {
                }

                protected function dashboardStats(): array
                {
                    return [
                        'total' => 9,
                        'emitted' => 7,
                        'cancelled' => 2,
                        'sandbox_mode' => true,
                    ];
                }

                protected function recentReceipts(): iterable
                {
                    return $this->recentReceipts;
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
            self::assertSame($recentReceipts, $response->data['recentReceipts'] ?? []);
        }

        public function testPendingReturnsViewWithInvoicesReadyForEmission(): void
        {
            $pendingInvoices = [
                new Invoice(id: 11, amount: 120.0),
                new Invoice(id: 12, amount: 220.0),
            ];

            $controller = new class ($pendingInvoices) extends InvoiceController {
                public function __construct(private readonly array $pendingInvoices)
                {
                }

                protected function pendingInvoices(): iterable
                {
                    return $this->pendingInvoices;
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

            $response = $controller->pending();

            self::assertSame('nfse::invoices.pending', $response->name);
            self::assertSame($pendingInvoices, $response->data['pendingInvoices'] ?? []);
            self::assertTrue($response->data['isReady'] ?? false);
            self::assertSame([
                'cnpj_prestador' => true,
                'municipio_ibge' => true,
                'item_lista_servico' => true,
                'certificate' => true,
            ], $response->data['checklist'] ?? []);
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
            self::assertSame('nfse.invoices.pending', $response->route);
            self::assertSame('Ambiente nao esta pronto para emissao.', $response->flash['error'] ?? null);
            self::assertSame([], NfseReceipt::$updateOrCreateCalls);
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
    }
}
