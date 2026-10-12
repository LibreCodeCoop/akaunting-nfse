<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * A 2xx-equivalent fake cannot exempt the taxpayer from a legal obligation.
 * Independent boundaries follow Ato Conjunto RFB/CGIBS 4/2026, art. 1 III,
 * and the CGNFS-e guidance of 2026-08-07 (non-rejection through 2026).
 *
 * This tests the real Akaunting automatic issuer, not just the policy method.
 * No certificate, fiscal network, production tenant or real tax document.
 */
final class FiscalNormativeIssuanceGuardTest extends FeatureTestCase
{
    /**
     * @return iterable<string,array{string,int,string,string,string,?string}>
     */
    public static function legalBoundaryCases(): iterable
    {
        yield 'ordinary LC116 before 01 October 2026' => ['2026-09-30', 1, '0107', '010701', '', null];
        yield 'ordinary LC116 from 01 October 2026' => ['2026-10-01', 1, '0107', '010701', '', 'ibs_cbs_required'];
        yield 'deferred LC116 before 01 December 2026' => ['2026-11-30', 1, '0103', '010301', '', null];
        yield 'deferred LC116 from 01 December 2026' => ['2026-12-01', 1, '0103', '010301', '', 'ibs_cbs_required'];
        yield 'Simples at the end of 2026' => ['2026-12-31', 3, '0107', '010701', '', null];
        yield 'Simples on 01 January 2027' => ['2027-01-01', 3, '0107', '010701', '', 'ibs_cbs_required'];
        yield 'MEI on 01 January 2027' => ['2027-01-01', 2, '0107', '010701', '', 'ibs_cbs_required'];
        yield 'unknown regime cannot be assumed exempt' => ['2026-10-08', 9, '0107', '010701', '', 'simples_option_unverifiable'];
        yield 'unsupported non ISS supply even before deadline' => ['2026-11-30', 1, '0107', '010701', 'lease', 'rtc_supply_category_unsupported'];
    }

    #[DataProvider('legalBoundaryCases')]
    public function testPermissiveSefinCannotOverrideLegalEmissionGate(
        string $date,
        int $simples,
        string $service,
        string $national,
        string $category,
        ?string $blockedReason,
    ): void {
        $invoice = $this->invoice($date, $service, $national, $category);
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $posts = 0;
        $submitted = null;
        $client = $this->permissiveSefin(static function (DpsData $dps) use (&$posts, &$submitted): void {
            $posts++;
            $submitted = $dps;
        });
        $settings = $this->settings($simples);
        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static fn (bool $sandbox): FiscalClientContext => new FiscalClientContext(
                $client,
                static function (): void {},
            ),
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );

        if ($blockedReason !== null) {
            try {
                $issuer->issue((int) $invoice->id, (string) $group['key']);
                self::fail('Fiscal omission must not be transmitted despite permissive fake SEFIN.');
            } catch (\LogicException $e) {
                self::assertStringContainsString($blockedReason, $e->getMessage());
            }

            self::assertSame(0, $posts);
            self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
            self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());

            return;
        }

        // A documented non-required operation must not be blocked by
        // requirements that take effect only at a later fiscal boundary.
        $receipt = $issuer->issue((int) $invoice->id, (string) $group['key']);
        self::assertSame('emitted', $receipt->status);
        self::assertSame(1, $posts);
        self::assertSame($national, $submitted?->codigoTributacaoNacional);
        self::assertNull($submitted?->ibsCbsFinalidade);
        self::assertSame(1, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testEnabledButIncompleteFiscalPayloadDoesNotReachPermissiveSefin(): void
    {
        $invoice = $this->invoice('2026-10-01', '0107', '010701', '');
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $posts = 0;
        $submitted = null;
        $client = $this->permissiveSefin(static function (DpsData $dps) use (&$posts, &$submitted): void {
            $posts++;
            $submitted = $dps;
        });
        $settings = array_replace($this->settings(1), [
            'nfse.ibs_cbs_enabled' => true,
            'nfse.ibs_cbs_c_ind_op' => '123',
            'nfse.ibs_cbs_ind_dest' => '0',
            'nfse.ibs_cbs_cst' => '00',
            'nfse.ibs_cbs_c_class_trib' => 'ABC001',
        ]);
        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static fn (bool $sandbox): FiscalClientContext => new FiscalClientContext(
                $client,
                static function (): void {},
            ),
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );

        try {
            $issuer->issue((int) $invoice->id, (string) $group['key']);
            self::fail('Missing mandatory codes must prevent a SEFIN attempt.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('ibs_cbs_required', $e->getMessage());
        }

        self::assertSame(0, $posts);
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
    }

    private function invoice(string $date, string $service, string $national, string $category): Document
    {
        $invoice = Document::factory()->invoice()->create([
            'issued_at' => $date . ' 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
        $invoice->items()->create([
            'company_id' => (int) $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Synthetic service',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'item_id' => (int) $item->id,
            'item_lista_servico' => $service,
            'codigo_tributacao_nacional' => $national,
            'rtc_supply_category' => $category === '' ? 'ordinary_lc116' : $category,
        ]);
        $invoice->load('items');

        return $invoice;
    }

    /** @return array<string,mixed> */
    private function settings(int $simples): array
    {
        return [
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
            'nfse.opcao_simples_nacional' => $simples,
            'nfse.regime_especial_tributacao' => '0',
            'nfse.regime_apuracao_simples_nacional' => $simples === 3 ? '1' : null,
            'nfse.sandbox_mode' => true,
            'nfse.tributacao_issqn' => 1,
            'nfse.tipo_retencao_iss' => 1,
            'nfse.ibs_cbs_enabled' => false,
        ];
    }
    /**
     * Initialize the fake only after Akaunting has bootstrapped the scoped runtime.
     * A permissive transport must never decide the fiscal obligation.
     */
    private function permissiveSefin(\Closure $onPost): NfseClientInterface
    {
        return new class ($onPost) implements NfseClientInterface {
            public function __construct(private readonly \Closure $onPost)
            {
            }

            public function emit(DpsData $dps): ReceiptData
            {
                ($this->onPost)($dps);

                return new ReceiptData('9901', str_repeat('9', 50), '2026-10-12T10:00:00-03:00');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('No recovery expected.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('No cancellation expected.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('No remote DANFSe expected.');
            }
        };
    }

}
