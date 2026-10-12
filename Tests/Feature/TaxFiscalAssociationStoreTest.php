<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\TaxFiscalAssociation;
use Modules\Nfse\Support\TaxFiscalAssociationStore;
use Tests\Feature\FeatureTestCase;

final class TaxFiscalAssociationStoreTest extends FeatureTestCase
{
    public function testConfirmsNonOverlappingDatedRevisionsAndRejectsOtherTenant(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $company = (int) $invoice->company_id;
        $tax = DB::table('taxes')->insertGetId([
            'company_id' => $company, 'name' => 'Homonymous tax',
            'rate' => '0.65', 'type' => 'normal', 'enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $store = new TaxFiscalAssociationStore();
        $first = $store->confirm(
            $company, $tax, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', '2026-06-30', 'synthetic-confirmation', 1,
        );
        $second = $store->confirm(
            $company, $tax, FiscalTaxIdentity::COFINS, FiscalTaxRole::PROVIDER_DUE,
            '2026-07-01', null, 'synthetic-revision', 1,
        );

        self::assertNotSame((int) $first->id, (int) $second->id);
        self::assertSame('pis', (string) $first->fresh()->fiscal_identity);

        try {
            $store->confirm(
                $company, $tax, FiscalTaxIdentity::IRRF, FiscalTaxRole::WITHHELD_BY_PAYER,
                '2026-06-30', '2026-09-01', 'synthetic-overlap', 1,
            );
            self::fail('Overlapping intervals must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Overlapping', $exception->getMessage());
        }

        try {
            $store->confirm(
                $company + 1, $tax, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
                '2026-01-01', null, 'synthetic-cross-tenant', 1,
            );
            self::fail('Cross-tenant tax association must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('does not belong', $exception->getMessage());
        }

        self::assertSame(2, TaxFiscalAssociation::query()->where('company_id', $company)->count());
    }

    public function testInvalidCalendarDateAndUnconfirmedOperatorCannotWrite(): void
    {
        $store = new TaxFiscalAssociationStore();

        try {
            $store->confirm(1, 1, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
                '2026-02-30', null, 'synthetic', 1);
            self::fail('Invalid date must fail before any DB write.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('calendar dates', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $store->confirm(1, 1, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', null, 'synthetic', 0);
    }
}
