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
use Modules\Nfse\Support\InvoiceTaxFactsResolver;
use Modules\Nfse\Support\TaxFiscalAssociationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

final class InvoiceTaxFactsResolverTest extends FeatureTestCase
{
    public function testTaxNameAndAmountCannotClassifyTwoIndependentCommercialLines(): void
    {
        [$invoice, $company, $tax] = $this->invoiceWithTaxRows(2);
        $resolver = new InvoiceTaxFactsResolver();

        $unclassified = $resolver->resolve($company, (int) $invoice->id, '2026-10-12');
        self::assertCount(2, $unclassified);
        self::assertNull($unclassified[0]->fiscalTaxCode);
        self::assertSame(500, $unclassified[0]->amountCents);
        self::assertSame(500, $unclassified[1]->amountCents);

        $this->confirm($company, $tax, FiscalTaxIdentity::COFINS, '2026-01-01');
        $classified = $resolver->resolve($company, (int) $invoice->id, '2026-10-12');

        self::assertSame('cofins', $classified[0]->fiscalTaxCode);
        self::assertSame('cofins', $classified[1]->fiscalTaxCode);
        self::assertNotSame($classified[0]->documentItemTaxRowId, $classified[1]->documentItemTaxRowId);
        self::assertSame([], $resolver->resolve($company + 1, (int) $invoice->id, '2026-10-12'));
    }

    #[DataProvider('competenceBoundaryCases')]
    public function testOnlyTheConfirmedFiscalIdentityEffectiveOnTheCompetenceDateIsUsed(
        string $competence,
        ?string $expectedIdentity,
    ): void {
        [$invoice, $company, $tax] = $this->invoiceWithTaxRows(1);
        $store = new TaxFiscalAssociationStore();
        $store->confirm(
            $company, $tax, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', '2026-06-30', 'synthetic-validity-1', 1,
        );
        $store->confirm(
            $company, $tax, FiscalTaxIdentity::COFINS, FiscalTaxRole::PROVIDER_DUE,
            '2026-07-01', '2026-09-30', 'synthetic-validity-2', 1,
        );

        $facts = (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, $competence);

        self::assertCount(1, $facts);
        self::assertSame($expectedIdentity, $facts[0]->fiscalTaxCode);
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function competenceBoundaryCases(): array
    {
        return [
            'before first confirmed effectiveness no identity is assumed' => ['2025-12-31', null],
            'effective from date is included' => ['2026-01-01', 'pis'],
            'effective until date is included' => ['2026-06-30', 'pis'],
            'next day begins the next approved identity' => ['2026-07-01', 'cofins'],
            'end of second confirmed interval is included' => ['2026-09-30', 'cofins'],
            'expired confirmation does not remain an implicit fallback' => ['2026-10-01', null],
        ];
    }

    public function testHomonymousNativeTaxIdsMustNotShareTheirFiscalAssociation(): void
    {
        [$invoice, $company, $firstTax, $itemIds] = $this->invoiceWithTaxRows(1);
        $secondTax = DB::table('taxes')->insertGetId([
            'company_id' => $company,
            'name' => 'PIS',
            'rate' => '5.00',
            'type' => 'normal',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_item_taxes')->insert([
            'company_id' => $company,
            'document_id' => (int) $invoice->id,
            'document_item_id' => $itemIds[0],
            'tax_id' => $secondTax,
            'type' => 'invoice',
            'name' => 'PIS',
            'rate' => '5.00',
            'amount' => '5.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->confirm($company, $firstTax, FiscalTaxIdentity::COFINS, '2026-01-01');

        $facts = (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, '2026-10-12');

        self::assertCount(2, $facts);
        self::assertSame($firstTax, $facts[0]->taxId);
        self::assertSame('cofins', $facts[0]->fiscalTaxCode);
        self::assertSame($secondTax, $facts[1]->taxId);
        self::assertNull($facts[1]->fiscalTaxCode);
        self::assertSame(500, $facts[1]->amountCents);
    }

    public function testArchivedAccountingTaxDoesNotEraseTheActiveInvoiceTaxObservation(): void
    {
        [$invoice, $company, $tax] = $this->invoiceWithTaxRows(1);
        $this->confirm($company, $tax, FiscalTaxIdentity::COFINS, '2026-01-01');

        DB::table('taxes')->where('id', $tax)->where('company_id', $company)->update([
            'name' => 'Renamed and archived synthetic tax',
            'deleted_at' => now(),
        ]);

        $facts = (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, '2026-10-12');

        self::assertCount(1, $facts);
        self::assertSame('cofins', $facts[0]->fiscalTaxCode);
        self::assertSame(500, $facts[0]->amountCents);
    }

    public function testRemovedCommercialItemNeverContributesAnActiveFiscalTaxFact(): void
    {
        [$invoice, $company, $tax, $itemIds] = $this->invoiceWithTaxRows(2);
        $this->confirm($company, $tax, FiscalTaxIdentity::PIS, '2026-01-01');
        $invoice->items()->where('id', $itemIds[0])->delete();

        $facts = (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, '2026-10-12');

        self::assertCount(1, $facts);
        self::assertSame($itemIds[1], $facts[0]->documentItemId);
    }

    public function testExplicitGroupSelectionDoesNotLeakTaxesFromOtherItems(): void
    {
        [$invoice, $company, $tax, $itemIds] = $this->invoiceWithTaxRows(2);
        $this->confirm($company, $tax, FiscalTaxIdentity::PIS, '2026-01-01');
        $resolver = new InvoiceTaxFactsResolver();

        $selected = $resolver->resolve($company, (int) $invoice->id, '2026-10-12', [$itemIds[1]]);
        self::assertCount(1, $selected);
        self::assertSame($itemIds[1], $selected[0]->documentItemId);
        self::assertSame([], $resolver->resolve($company, (int) $invoice->id, '2026-10-12', []));
    }

    public function testOverlappingAssignmentsDoNotSilentlyChooseTheNewerRow(): void
    {
        [$invoice, $company, $tax] = $this->invoiceWithTaxRows(1);
        $this->confirm($company, $tax, FiscalTaxIdentity::COFINS, '2026-01-01');
        TaxFiscalAssociation::query()->create([
            'company_id' => $company,
            'tax_id' => $tax,
            'effective_from' => '2026-10-01',
            'fiscal_identity' => 'pis',
            'fiscal_role' => 'provider_due',
            'confirmation_kind' => 'operator_confirmed',
            'source_reference' => 'synthetic-direct-write-conflict',
            'confirmed_by' => 1,
            'confirmed_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, '2026-10-12');
    }

    #[DataProvider('invalidEvidenceCases')]
    public function testUnverifiedAssociationCannotBecomeAConclusiveFiscalIdentity(
        string $identity,
        string $role,
        string $source,
        int $operator,
        bool $confirmed,
        string $confirmationKind,
    ): void {
        [$invoice, $company, $tax] = $this->invoiceWithTaxRows(1);
        TaxFiscalAssociation::query()->create([
            'company_id' => $company,
            'tax_id' => $tax,
            'effective_from' => '2026-01-01',
            'fiscal_identity' => $identity,
            'fiscal_role' => $role,
            'confirmation_kind' => $confirmationKind,
            'source_reference' => $source,
            'confirmed_by' => $operator,
            'confirmed_at' => $confirmed ? now() : null,
        ]);

        $this->expectException(\DomainException::class);
        (new InvoiceTaxFactsResolver())->resolve($company, (int) $invoice->id, '2026-10-12');
    }

    /** @return array<string,array{0:string,1:string,2:string,3:int,4:bool,5:string}> */
    public static function invalidEvidenceCases(): array
    {
        return [
            'legacy name is not a verified fiscal identity' => ['PIS by name', 'provider_due', 'synthetic', 1, true, 'operator_confirmed'],
            'unknown role is not treated as provider due' => ['pis', 'unknown-role', 'synthetic', 1, true, 'operator_confirmed'],
            'missing normative or audit reference blocks' => ['pis', 'provider_due', ' ', 1, true, 'operator_confirmed'],
            'missing human confirmation identity blocks' => ['pis', 'provider_due', 'synthetic', 0, true, 'operator_confirmed'],
            'absent confirmation time blocks' => ['pis', 'provider_due', 'synthetic', 1, false, 'operator_confirmed'],
            'heuristically guessed classification is untrusted' => ['pis', 'provider_due', 'synthetic', 1, true, 'inferred_from_name'],
        ];
    }

    #[DataProvider('invalidSelectionCases')]
    public function testScopeMustNotInventCompanyInvoiceOrCompetence(
        int $company,
        int $invoice,
        string $competence,
        ?array $itemIds,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceTaxFactsResolver())->resolve($company, $invoice, $competence, $itemIds);
    }

    /** @return array<string,array{0:int,1:int,2:string,3:list<int>|null}> */
    public static function invalidSelectionCases(): array
    {
        return [
            'missing company identity' => [0, 1, '2026-10-12', null],
            'missing invoice identity' => [1, 0, '2026-10-12', null],
            'invalid calendar date' => [1, 1, '2026-02-30', null],
            'invalid fiscal group item ID' => [1, 1, '2026-10-12', [0]],
        ];
    }

    private function confirm(int $company, int $tax, FiscalTaxIdentity $identity, string $from): void
    {
        (new TaxFiscalAssociationStore())->confirm(
            $company, $tax, $identity, FiscalTaxRole::PROVIDER_DUE,
            $from, null, 'synthetic-confirmed-tax-association', 1,
        );
    }

    /**
     * Each returned item is a persisted, active Akaunting document line.
     * @return array{0:Document,1:int,2:int,3:list<int>}
     */
    private function invoiceWithTaxRows(int $count): array
    {
        $invoice = Document::factory()->invoice()->create();
        $company = (int) $invoice->company_id;
        $tax = DB::table('taxes')->insertGetId([
            'company_id' => $company,
            'name' => 'PIS',
            'rate' => '5.00',
            'type' => 'normal',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $itemIds = [];
        for ($i = 1; $i <= $count; ++$i) {
            $item = $invoice->items()->create([
                'company_id' => $company,
                'type' => 'item',
                'name' => 'Synthetic service ' . $i,
                'quantity' => 1,
                'price' => 100,
                'total' => 100,
            ]);
            $itemIds[] = (int) $item->id;
            DB::table('document_item_taxes')->insert([
                'company_id' => $company,
                'document_id' => (int) $invoice->id,
                'document_item_id' => (int) $item->id,
                'tax_id' => $tax,
                'type' => 'invoice',
                'name' => 'PIS',
                'rate' => '5.00',
                'amount' => '5.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$invoice, $company, $tax, $itemIds];
    }
}
