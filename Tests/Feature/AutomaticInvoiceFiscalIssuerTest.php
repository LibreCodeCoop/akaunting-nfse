<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\ApproximateTaxSource;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder;
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
        $invoice->notes = 'Observação geral de faturamento';
        $invoice->save();
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
        self::assertStringNotContainsString('Centro de custo: TESTE-001', (string) $client->lastDps?->discriminacao);
        self::assertStringContainsString('Consultoria', (string) $client->lastDps?->discriminacao);
        self::assertStringContainsString('Observação geral de faturamento', (string) $client->lastDps?->discriminacao);
        self::assertSame(1, $closed);
        $attempt = NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame('automatic', $attempt->origin);
        self::assertSame('authorized', $attempt->status);
        self::assertSame((int) $receipt->id, (int) $attempt->receipt_id);

        $retry = $issuer->issue((int) $invoice->id, (string) $group['key']);

        self::assertSame($receipt->id, $retry->id);
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, $closed);
    }

    public function testAutomaticIssuanceUsesVersionedSourceInRealDpsXmlAndPinsItsOrigin(): void
    {
        $invoice = $this->invoiceWithProfile();
        $invoice->issued_at = '2026-10-05 09:00:00';
        $invoice->save();
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];

        ApproximateTaxSource::query()->create([
            'company_id' => (int) $invoice->company_id,
            'national_service_code' => '010701',
            'simples_option' => 2,
            'competence_from' => '2026-01-01',
            'competence_until' => '2026-09-30',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Synthetic institutional historical edition',
            'source_version' => 'synth-2026-old',
            'source_reference' => 'synthetic-archive/old',
            'federal_percent' => '8.15',
            'state_percent' => '0.00',
            'municipal_percent' => '3.10',
        ]);
        $currentSource = ApproximateTaxSource::query()->create([
            'company_id' => (int) $invoice->company_id,
            'national_service_code' => '010701',
            'simples_option' => 2,
            'competence_from' => '2026-10-01',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Synthetic institutional current edition',
            'source_version' => 'synth-2026-current',
            'source_reference' => 'synthetic-archive/current',
            'federal_percent' => '7.45',
            'state_percent' => '0.00',
            'municipal_percent' => '4.10',
        ]);

        $client = new class () implements NfseClientInterface {
            public int $emits = 0;
            public string $dpsXml = '';

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                $this->dpsXml = (new XmlBuilder())->buildDps($dps);

                return new ReceiptData('9210', str_repeat('2', 50), '2026-10-05T16:00:00-03:00');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Fake query not expected.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Fake cancellation not expected.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Fake DANFSe transport not expected.');
            }
        };
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
            clientContextFactory: static fn (bool $sandbox): FiscalClientContext => new FiscalClientContext(
                $client,
                static function (): void {},
            ),
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );

        $receipt = $issuer->issue((int) $invoice->id, (string) $group['key']);

        self::assertSame('9210', (string) $receipt->nfse_number);
        self::assertSame(1, $client->emits);
        self::assertStringContainsString('<pTotTribFed>7.45</pTotTribFed>', $client->dpsXml);
        self::assertStringContainsString('<pTotTribEst>0.00</pTotTribEst>', $client->dpsXml);
        self::assertStringContainsString('<pTotTribMun>4.10</pTotTribMun>', $client->dpsXml);
        self::assertStringNotContainsString('<pTotTribMun>3.10</pTotTribMun>', $client->dpsXml);
        self::assertStringNotContainsString('<indTotTrib>0</indTotTrib>', $client->dpsXml);

        $attempt = NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame('sourced_estimate', $attempt->approximate_tax_evidence['status']);
        self::assertSame((int) $currentSource->id, $attempt->approximate_tax_evidence['source']['id']);
        self::assertSame('synth-2026-current', $attempt->approximate_tax_evidence['source']['version']);
        self::assertSame('synthetic-archive/current', $attempt->approximate_tax_evidence['source']['reference']);
        self::assertSame('2026-10-05', $attempt->approximate_tax_evidence['source']['competence']);
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
            'description' => 'Serviço técnico\nCentro de custo: TESTE-001',
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
