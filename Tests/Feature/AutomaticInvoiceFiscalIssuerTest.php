<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Tests\Feature\FeatureTestCase;

final class AutomaticInvoiceFiscalIssuerTest extends FeatureTestCase
{
    public function testContainerBindsConcreteBulkIssuer(): void
    {
        self::assertInstanceOf(
            AutomaticInvoiceFiscalIssuer::class,
            $this->app->make(BulkEmissionUnitIssuerInterface::class),
        );
    }

    public function testContainerResolvesBulkProcessorWithConcreteIssuer(): void
    {
        self::assertInstanceOf(
            BulkEmissionUnitProcessor::class,
            $this->app->make(BulkEmissionUnitProcessor::class),
        );
    }

    public function testIssuesOneResolvedGroupAndReusesPersistedReceipt(): void
    {
        $invoice = $this->invoiceWithProfile();
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $client = new class () implements NfseClientInterface {
            public int $emits = 0;
            public ?DpsData $lastDps = null;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                $this->lastDps = $dps;

                return new ReceiptData(
                    nfseNumber: '9101',
                    chaveAcesso: str_repeat('9', 50),
                    dataEmissao: '2026-10-05T16:00:00-03:00',
                );
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Not used.');
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
        $closed = 0;
        $settings = [
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
            'nfse.opcao_simples_nacional' => 2,
            'nfse.sandbox_mode' => true,
            'nfse.tributacao_issqn' => 1,
            'nfse.tipo_retencao_iss' => 1,
            'nfse.ibs_cbs_enabled' => false,
        ];
        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static function (bool $sandbox) use ($client, &$closed): FiscalClientContext {
                self::assertTrue($sandbox);

                return new FiscalClientContext(
                    $client,
                    static function () use (&$closed): void {
                        $closed++;
                    },
                );
            },
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );

        $receipt = $issuer->issue((int) $invoice->id, (string) $group['key']);

        self::assertSame('9101', $receipt->nfse_number);
        self::assertSame(1, $client->emits);
        self::assertSame('100.00', $client->lastDps?->valorServico);
        self::assertSame('0107', $client->lastDps?->itemListaServico);
        self::assertSame('010701', $client->lastDps?->codigoTributacaoNacional);
        self::assertSame(1, $closed);

        $retry = $issuer->issue((int) $invoice->id, (string) $group['key']);

        self::assertSame($receipt->id, $retry->id);
        self::assertSame(1, $client->emits);
        self::assertSame(1, $closed);
    }

    public function testRejectsChangedGroupBeforeOpeningFiscalClient(): void
    {
        $invoice = $this->invoiceWithProfile();
        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static function (): FiscalClientContext {
                throw new \LogicException('Fiscal client must not open.');
            },
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.opcao_simples_nacional' => 2,
                default => $default,
            },
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Fiscal group changed');

        $issuer->issue((int) $invoice->id, 'service:wrong|tax:wrong|rate:0');
    }

    private function invoiceWithProfile(): Document
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $item = Item::factory()->create(['company_id' => $invoice->company_id]);
        $invoice->items()->create([
            'company_id' => $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Consultoria',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => $invoice->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);
        $invoice->load('items');

        return $invoice;
    }
}
