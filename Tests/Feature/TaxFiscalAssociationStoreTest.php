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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

final class TaxFiscalAssociationStoreTest extends FeatureTestCase
{
    #[DataProvider('revisionWindowCases')]
    public function testFiscalAssociationWindowsAreInclusiveAndAppendOnly(
        ?string $originalUntil,
        string $replacementFrom,
        ?string $replacementUntil,
        bool $overlaps,
    ): void {
        [$company, $tax] = $this->companyTax();
        $store = new TaxFiscalAssociationStore();
        $original = $store->confirm(
            $company, $tax, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', $originalUntil, 'synthetic-fiscal-review-a', 1,
        );

        if ($overlaps) {
            try {
                $store->confirm(
                    $company, $tax, FiscalTaxIdentity::COFINS, FiscalTaxRole::PROVIDER_DUE,
                    $replacementFrom, $replacementUntil, 'synthetic-fiscal-review-b', 1,
                );
                self::fail('Two fiscal identities cannot be effective for the same tax on the same date.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('Overlapping', $exception->getMessage());
            }

            self::assertSame(1, TaxFiscalAssociation::query()->where('company_id', $company)->count());
        } else {
            $replacement = $store->confirm(
                $company, $tax, FiscalTaxIdentity::COFINS, FiscalTaxRole::PROVIDER_DUE,
                $replacementFrom, $replacementUntil, 'synthetic-fiscal-review-b', 1,
            );
            self::assertNotSame((int) $original->id, (int) $replacement->id);
            self::assertSame(2, TaxFiscalAssociation::query()->where('company_id', $company)->count());
        }

        self::assertSame('pis', (string) $original->fresh()->fiscal_identity);
    }

    /**
     * @return array<string,array{0:?string,1:string,2:?string,3:bool}>
     */
    public static function revisionWindowCases(): array
    {
        return [
            'end date itself belongs to the previous assignment' => ['2026-06-30', '2026-06-30', '2026-07-01', true],
            'inside previous window is an overlapping reclassification' => ['2026-06-30', '2026-03-01', '2026-03-31', true],
            'day after expiry begins a valid new assignment' => ['2026-06-30', '2026-07-01', null, false],
            'historical gap does not modify old classification' => ['2026-06-30', '2026-08-01', '2026-12-31', false],
            'open-ended assignment blocks a later overlap' => [null, '2026-07-01', null, true],
        ];
    }

    public function testTaxFromAnotherCompanyCannotReceiveAConfirmedIdentity(): void
    {
        [$company, $tax] = $this->companyTax();
        $store = new TaxFiscalAssociationStore();

        try {
            $store->confirm(
                $company + 1, $tax, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
                '2026-01-01', null, 'synthetic-cross-tenant-attempt', 1,
            );
            self::fail('A tax from another company must not be classified.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('does not belong', $exception->getMessage());
        }

        self::assertSame(0, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
    }

    #[DataProvider('invalidConfirmationCases')]
    public function testUnverifiableAssociationEvidenceCannotBeSaved(
        int $companyId,
        int $taxId,
        string $from,
        ?string $until,
        string $source,
        int $operatorId,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        (new TaxFiscalAssociationStore())->confirm(
            $companyId, $taxId, FiscalTaxIdentity::PIS, FiscalTaxRole::PROVIDER_DUE,
            $from, $until, $source, $operatorId,
        );
    }

    /**
     * @return array<string,array{0:int,1:int,2:string,3:?string,4:string,5:int}>
     */
    public static function invalidConfirmationCases(): array
    {
        return [
            'company must be an existing positive identifier' => [0, 1, '2026-01-01', null, 'synthetic-proof', 1],
            'tax cannot have an unknown identifier' => [1, 0, '2026-01-01', null, 'synthetic-proof', 1],
            'operator confirmation cannot be anonymous' => [1, 1, '2026-01-01', null, 'synthetic-proof', 0],
            'invalid leap-day spelling is rejected' => [1, 1, '2026-02-30', null, 'synthetic-proof', 1],
            'date is ISO and not locale formatted' => [1, 1, '2026/01/01', null, 'synthetic-proof', 1],
            'end date must be valid' => [1, 1, '2026-01-01', '2026-02-30', 'synthetic-proof', 1],
            'end cannot precede start' => [1, 1, '2026-07-01', '2026-06-30', 'synthetic-proof', 1],
            'review reference cannot be omitted' => [1, 1, '2026-01-01', null, ' ', 1],
        ];
    }

    /** @return array{0:int,1:int} */
    private function companyTax(): array
    {
        $invoice = Document::factory()->invoice()->create();
        $company = (int) $invoice->company_id;
        $tax = DB::table('taxes')->insertGetId([
            'company_id' => $company,
            'name' => 'Synthetic tax (same name permitted)',
            'rate' => '0.65',
            'type' => 'normal',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$company, $tax];
    }
}
