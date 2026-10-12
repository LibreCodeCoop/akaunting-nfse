<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\InvoiceFederalPayloadResolver;
use Modules\Nfse\Support\TaxFiscalAssociationStore;
use Tests\Feature\FeatureTestCase;

/**
 * Real Akaunting persistence with synthetic tax and fiscal data only.
 * No certificate, real SEFIN request or authorized document is generated.
 */
final class ConfirmedInvoiceFederalEmissionTest extends FeatureTestCase
{
    public function testConfirmedTaxWithoutLegalBaseCannotOpenFiscalGateway(): void
    {
        [$invoice, $itemId] = $this->invoice();
        $taxId = $this->tax($invoice, $itemId, 'Unrelated accounting name', 'normal', '16.50', '1.65');
        (new TaxFiscalAssociationStore())->confirm(
            (int) $invoice->company_id,
            $taxId,
            FiscalTaxIdentity::PIS,
            FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01',
            null,
            'synthetic-confirmed-pis-proof',
            1,
        );

        $opens = 0;
        $settings = $this->settings([
            'nfse.federal_piscofins_situacao_tributaria' => '1',
        ]);
        $issuer = new AutomaticInvoiceFiscalIssuer(
            clientContextFactory: static function (bool $sandbox) use (&$opens): never {
                $opens++;

                throw new \LogicException('No fake SEFIN transport should be constructed.');
            },
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );
        $group = (new \Modules\Nfse\Support\InvoiceFiscalContextResolver())->groups($invoice)[0];

        try {
            $issuer->issue((int) $invoice->id, (string) $group['key']);
            self::fail('Missing tax-specific fiscal base must not generate a DPS.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('BASE-UNVERIFIABLE', $exception->getMessage());
        }

        self::assertSame(0, $opens);
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testConfirmedNt007WithholdingIsIndependentOfGrossAndAccountingNames(): void
    {
        [$invoice, $itemId] = $this->invoice();
        $company = (int) $invoice->company_id;
        $pisId = $this->tax($invoice, $itemId, 'Renamed first contribution', 'withholding', '65.00', '0.65');
        $cofinsId = $this->tax($invoice, $itemId, 'Renamed second contribution', 'withholding', '300.00', '3.00');
        $store = new TaxFiscalAssociationStore();
        $store->confirm(
            $company, $pisId, FiscalTaxIdentity::PIS, FiscalTaxRole::WITHHELD_BY_PAYER,
            '2026-01-01', null, 'synthetic-NT007-PIS', 1,
        );
        $store->confirm(
            $company, $cofinsId, FiscalTaxIdentity::COFINS, FiscalTaxRole::WITHHELD_BY_PAYER,
            '2026-01-01', null, 'synthetic-NT007-COFINS', 1,
        );
        // Changing commercial names never changes the confirmed identity.
        DB::table('taxes')->where('id', $pisId)->where('company_id', $company)
            ->update(['name' => 'Renamed again']);
        $settings = $this->settings([
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '4',
            'nfse.federal_piscofins_aliquota_pis' => '99.99',
        ]);
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );

        $payload = $resolver->resolve($invoice, [$itemId], 10000.00, null, '010701', '2026-09-30');

        self::assertSame('365.00', $payload['federalValorCsll']);
        self::assertSame('', $payload['federalPiscofinsValorPis']);
        self::assertSame('', $payload['federalPiscofinsValorCofins']);
        self::assertSame('', $payload['federalPiscofinsBaseCalculo']);
        self::assertSame('unverifiable', $payload['federalTaxEvidence']['legal_incidence']);
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
    }

    /** @return array{0:Document,1:int} */
    private function invoice(): array
    {
        $invoice = Document::factory()->invoice()->create([
            'issued_at' => '2026-09-30 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
        $documentItem = $invoice->items()->create([
            'company_id' => (int) $invoice->company_id,
            'type' => 'item',
            'item_id' => (int) $item->id,
            'name' => 'Synthetic professional service',
            'quantity' => 1,
            'price' => '1000.00',
            'total' => '1000.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'item_id' => (int) $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'rtc_supply_category' => 'ordinary_lc116',
        ]);
        $invoice->load('items', 'contact');

        return [$invoice, (int) $documentItem->id];
    }

    private function tax(
        Document $invoice,
        int $itemId,
        string $name,
        string $type,
        string $amount,
        string $rate,
    ): int {
        $company = (int) $invoice->company_id;
        $taxId = DB::table('taxes')->insertGetId([
            'company_id' => $company,
            'name' => $name,
            'type' => $type,
            'rate' => $rate,
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_item_taxes')->insert([
            'company_id' => $company,
            'document_id' => (int) $invoice->id,
            'document_item_id' => $itemId,
            'tax_id' => $taxId,
            'type' => 'invoice',
            'name' => $name,
            'rate' => $rate,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $taxId;
    }

    /** @param array<string,mixed> $extra
     *  @return array<string,mixed>
     */
    private function settings(array $extra): array
    {
        return array_replace([
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
            'nfse.opcao_simples_nacional' => 2,
            'nfse.regime_especial_tributacao' => '0',
            'nfse.sandbox_mode' => true,
            'nfse.tributacao_issqn' => 1,
            'nfse.tipo_retencao_iss' => 1,
            'nfse.ibs_cbs_enabled' => false,
            'nfse.enforce_item_federal_taxes' => false,
        ], $extra);
    }
}
