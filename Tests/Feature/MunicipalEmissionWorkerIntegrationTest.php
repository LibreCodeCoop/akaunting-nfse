<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionRunRecorder;
use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Jobs\ProcessBulkEmissionUnit;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Tests\Feature\FeatureTestCase;

/**
 * Real Akaunting invoices and persisted queue units, fake SEFIN responses.
 * The job, bulk processor, issuer, group adapter and DPS journal are real.
 */
final class MunicipalEmissionWorkerIntegrationTest extends FeatureTestCase
{
    public function testQueuedWorkerEmitsOnceAndTerminalReplayNeverPostsAgain(): void
    {
        [$invoice, $run] = $this->invoiceAndRun();
        $client = $this->fakeSefin();
        $worker = new BulkEmissionUnitProcessor($this->issuer($client));
        $job = new ProcessBulkEmissionUnit((int) $run['units'][0]->id);

        self::assertSame(1, $job->tries);
        $job->handle($worker);
        $job->handle($worker);

        self::assertSame(BulkEmissionStatusPolicy::ISSUED, $run['units'][0]->fresh()?->status);
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame('automatic', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->origin);
    }

    public function testQueuedWorkerRevalidatesChangedGroupWithoutOpeningSefin(): void
    {
        [$invoice, $run] = $this->invoiceAndRun();
        ItemFiscalProfile::query()
            ->where('company_id', $invoice->company_id)
            ->update(['codigo_tributacao_nacional' => '010101']);

        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static function (): FiscalClientContext {
                throw new \LogicException('Changed group must not open the SEFIN transport.');
            },
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.opcao_simples_nacional' => 2,
                default => $default,
            },
        );
        (new ProcessBulkEmissionUnit((int) $run['units'][0]->id))
            ->handle(new BulkEmissionUnitProcessor($issuer));

        self::assertSame(BulkEmissionStatusPolicy::BLOCKED, $run['units'][0]->fresh()?->status);
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testOfficialE0312InQueuedWorkerIsRejectedWithoutReceiptOrRepeatPost(): void
    {
        [$invoice, $run] = $this->invoiceAndRun();
        $client = $this->fakeSefin();
        $client->emitError = new IssuanceException(
            'Synthetic SEFIN E0312',
            NfseErrorCode::IssuanceRejected,
            422,
            ['erros' => [['codigo' => 'E0312', 'descricao' => 'Servico nao administrado nesta competencia.']]],
        );
        $worker = new BulkEmissionUnitProcessor($this->issuer($client));
        $job = new ProcessBulkEmissionUnit((int) $run['units'][0]->id);
        $job->handle($worker);
        $job->handle($worker);

        self::assertSame(BulkEmissionStatusPolicy::REJECTED, $run['units'][0]->fresh()?->status);
        self::assertSame('official_rejection', $run['units'][0]->fresh()?->error_type);
        self::assertSame(1, $client->emits);
        self::assertSame('E0312', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->official_code);
        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testAmbiguousQueuedPostRecoversByDpsQueryWithoutAnotherPost(): void
    {
        [$invoice, $run] = $this->invoiceAndRun();
        $client = $this->fakeSefin();
        $client->emitError = new NetworkException('Synthetic timeout after POST');
        $worker = new BulkEmissionUnitProcessor($this->issuer($client));
        $job = new ProcessBulkEmissionUnit((int) $run['units'][0]->id);

        $job->handle($worker);
        self::assertSame(BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR, $run['units'][0]->fresh()?->status);
        self::assertSame(1, $client->emits);
        self::assertSame('ambiguous', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);

        $client->recovered = new ReceiptData('9311', str_repeat('7', 50), '2026-10-12T11:00:00-03:00');
        $job->handle($worker);

        self::assertSame(BulkEmissionStatusPolicy::ISSUED, $run['units'][0]->fresh()?->status);
        self::assertSame(1, $client->emits);
        self::assertGreaterThanOrEqual(2, $client->dpsQueries);
        self::assertSame('authorized', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    /** @return array{Document,array<string,mixed>} */
    private function invoiceAndRun(): array
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) company_id(),
            'issued_at' => '2026-09-30 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
        $invoice->items()->create([
            'company_id' => (int) $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Consultoria sintética',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '123',
        ]);
        $invoice->load('items');
        $group = (new InvoiceFiscalContextResolver())->groups($invoice);
        self::assertCount(1, $group);
        $run = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => (string) $group[0]['key'],
            ]],
        );

        return [$invoice, $run];
    }

    private function issuer(NfseClientInterface $client): AutomaticInvoiceFiscalIssuer
    {
        $settings = [
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
            'nfse.opcao_simples_nacional' => 2,
            'nfse.regime_especial_tributacao' => '0',
            'nfse.sandbox_mode' => true,
            'nfse.tributacao_issqn' => 1,
            'nfse.tipo_retencao_iss' => 1,
            'nfse.ibs_cbs_enabled' => false,
        ];

        return new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static fn (bool $sandbox): FiscalClientContext => new FiscalClientContext(
                $client,
                static function (): void {
                },
            ),
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );
    }

    private function fakeSefin(): NfseClientInterface
    {
        return new class () implements NfseClientInterface {
            public int $emits = 0;
            public int $dpsQueries = 0;
            public ?\Throwable $emitError = null;
            public ?ReceiptData $recovered = null;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;

                if ($this->emitError instanceof \Throwable) {
                    throw $this->emitError;
                }

                return new ReceiptData('9301', str_repeat('8', 50), '2026-10-12T11:00:00-03:00');
            }

            public function queryDps(string $idDps): string
            {
                $this->dpsQueries++;

                return $this->recovered?->chaveAcesso ?? '';
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                if (!$this->recovered instanceof ReceiptData) {
                    throw new \LogicException('Synthetic recovery is unavailable.');
                }

                return $this->recovered;
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
