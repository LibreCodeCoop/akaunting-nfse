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
use Modules\Nfse\Support\InvoiceTaxFactsResolver;
use Tests\Feature\FeatureTestCase;

final class InvoiceTaxFactsResolverTest extends FeatureTestCase
{
    public function testCompanyAndTaxIdAreRequiredForClassifyingPersistedEqualAmounts(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $company = (int) $invoice->company_id;
        $tax = DB::table('taxes')->insertGetId([
            'company_id' => $company, 'name' => 'PIS', 'rate' => '5.00',
            'type' => 'normal', 'enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        foreach ([1, 2] as $i) {
            $item = $invoice->items()->create([
                'company_id' => $company, 'type' => 'item',
                'name' => 'Synthetic service ' . $i,
                'quantity' => 1, 'price' => 100, 'total' => 100,
            ]);
            DB::table('document_item_taxes')->insert([
                'company_id' => $company,
                'document_id' => (int) $invoice->id,
                'document_item_id' => (int) $item->id,
                'tax_id' => $tax,
                'type' => 'invoice',
                'name' => 'PIS',
                'rate' => '5.00',
                'amount' => '5.00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $resolver = new InvoiceTaxFactsResolver();
        $unknown = $resolver->resolve($company, (int) $invoice->id, '2026-10-12');
        self::assertCount(2, $unknown);
        self::assertNull($unknown[0]->fiscalTaxCode);
        self::assertSame(500, $unknown[0]->amountCents);
        self::assertSame(500, $unknown[1]->amountCents);

        (new TaxFiscalAssociationStore())->confirm(
            $company, $tax, FiscalTaxIdentity::COFINS, FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', null, 'synthetic-approved-classification', 1,
        );

        $facts = $resolver->resolve($company, (int) $invoice->id, '2026-10-12');
        self::assertSame('cofins', $facts[0]->fiscalTaxCode);
        self::assertSame('cofins', $facts[1]->fiscalTaxCode);
        self::assertNotSame($facts[0]->documentItemTaxRowId, $facts[1]->documentItemTaxRowId);
        self::assertSame([], $resolver->resolve($company + 1, (int) $invoice->id, '2026-10-12'));

        // Changing a human-readable label or archiving the native tax may
        // not erase or reclassify already persisted commercial tax rows.
        DB::table('taxes')->where('id', $tax)->where('company_id', $company)->update([
            'name' => 'Renamed and archived synthetic tax',
            'deleted_at' => now(),
        ]);
        $archived = $resolver->resolve($company, (int) $invoice->id, '2026-10-12');
        self::assertCount(2, $archived);
        self::assertSame('cofins', $archived[0]->fiscalTaxCode);

        // Bypassing the store must not make an overlapping revision silently
        // win by a higher ID or a more recent effective date.
        TaxFiscalAssociation::query()->create([
            'company_id' => $company, 'tax_id' => $tax,
            'effective_from' => '2026-10-01',
            'fiscal_identity' => 'pis', 'fiscal_role' => 'provider_due',
            'confirmation_kind' => 'operator_confirmed',
            'source_reference' => 'synthetic-duplicate-conflict',
            'confirmed_by' => 1, 'confirmed_at' => now(),
        ]);
        $this->expectException(\DomainException::class);
        $resolver->resolve($company, (int) $invoice->id, '2026-10-12');
    }
}
