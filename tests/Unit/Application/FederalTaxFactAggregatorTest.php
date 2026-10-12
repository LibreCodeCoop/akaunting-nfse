<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalTaxFactAggregator;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalTaxFactAggregatorTest extends TestCase
{
    /**
     * @param list<array{item:int,tax:int,row:int}> $observations
     */
    #[DataProvider('persistedOccurrenceCases')]
    public function testOnlyIdenticalPersistedOccurrencesAreDeduplicated(array $observations, int $expectedCents): void
    {
        $facts = [];
        foreach ($observations as $observation) {
            $facts[] = self::fact(
                itemId: $observation['item'],
                taxId: $observation['tax'],
                rowId: $observation['row'],
            );
        }

        $result = (new FederalTaxFactAggregator())->aggregate($facts);

        self::assertSame($expectedCents, $result['due']['pis']);
        self::assertSame(intdiv($expectedCents, 500), $result['row_count']);
    }

    /**
     * @return array<string,array{0:list<array{item:int,tax:int,row:int}>,1:int}>
     */
    public static function persistedOccurrenceCases(): array
    {
        return [
            'same persisted row loaded twice counts once' => [
                [['item' => 101, 'tax' => 7, 'row' => 5001], ['item' => 101, 'tax' => 7, 'row' => 5001]], 500,
            ],
            'two service lines with equal amount and tax ID both count' => [
                [['item' => 101, 'tax' => 7, 'row' => 5001], ['item' => 102, 'tax' => 7, 'row' => 5002]], 1000,
            ],
            'two native tax IDs on the same line both count' => [
                [['item' => 101, 'tax' => 7, 'row' => 5001], ['item' => 101, 'tax' => 8, 'row' => 5002]], 1000,
            ],
            'two distinct tax rows for the same native tax and line both count' => [
                [['item' => 101, 'tax' => 7, 'row' => 5001], ['item' => 101, 'tax' => 7, 'row' => 5002]], 1000,
            ],
        ];
    }

    #[DataProvider('accountingRoleCases')]
    public function testNativeAccountingTypeMustMatchTheConfirmedFiscalRole(
        string $accountingType,
        FiscalTaxRole $role,
        bool $allowed,
    ): void {
        if (!$allowed) {
            $this->expectException(\DomainException::class);
        }

        $result = (new FederalTaxFactAggregator())->aggregate([
            self::fact(accountingType: $accountingType, role: $role),
        ]);
        $bucket = $role === FiscalTaxRole::WITHHELD_BY_PAYER ? 'retained' : 'due';
        self::assertSame(500, $result[$bucket]['pis']);
    }

    /**
     * @return array<string,array{0:string,1:FiscalTaxRole,2:bool}>
     */
    public static function accountingRoleCases(): array
    {
        return [
            'normal tax confirmed as provider due' => ['normal', FiscalTaxRole::PROVIDER_DUE, true],
            'withholding confirmed as actually retained' => ['withholding', FiscalTaxRole::WITHHELD_BY_PAYER, true],
            'normal cannot prove payer withholding' => ['normal', FiscalTaxRole::WITHHELD_BY_PAYER, false],
            'withholding cannot become provider due' => ['withholding', FiscalTaxRole::PROVIDER_DUE, false],
            'inclusive needs independent tax base' => ['inclusive', FiscalTaxRole::PROVIDER_DUE, false],
            'compound needs ordered statutory calculation' => ['compound', FiscalTaxRole::PROVIDER_DUE, false],
            'fixed cannot be interpreted as a percentage' => ['fixed', FiscalTaxRole::PROVIDER_DUE, false],
            'estimated disclosure is not a native invoice charge' => ['normal', FiscalTaxRole::ESTIMATED_DISCLOSURE, false],
        ];
    }

    #[DataProvider('taxIdentityCases')]
    public function testTaxIdentityAndRoleDetermineTheOnlyAllowedFederalBucket(
        string $identity,
        FiscalTaxRole $role,
        string $accountingType,
        ?string $expectedBucket,
    ): void {
        $result = (new FederalTaxFactAggregator())->aggregate([
            self::fact(identity: $identity, role: $role, accountingType: $accountingType),
        ]);

        $expected = array_fill_keys(['pis', 'cofins', 'csll', 'irrf', 'cp'], 0);
        if ($expectedBucket !== null) {
            $expected[$expectedBucket] = 500;
        }
        $destination = $role === FiscalTaxRole::WITHHELD_BY_PAYER ? 'retained' : 'due';
        self::assertSame($expected, $result[$destination]);
        self::assertSame(1, $result['row_count']);
    }

    /**
     * @return array<string,array{0:string,1:FiscalTaxRole,2:string,3:?string}>
     */
    public static function taxIdentityCases(): array
    {
        return [
            'PIS provider due is not PIS withheld' => ['pis', FiscalTaxRole::PROVIDER_DUE, 'normal', 'pis'],
            'PIS actually withheld does not become own debit' => ['pis', FiscalTaxRole::WITHHELD_BY_PAYER, 'withholding', 'pis'],
            'COFINS own debit' => ['cofins', FiscalTaxRole::PROVIDER_DUE, 'normal', 'cofins'],
            'CSLL retained' => ['csll', FiscalTaxRole::WITHHELD_BY_PAYER, 'withholding', 'csll'],
            'IRRF retained' => ['irrf', FiscalTaxRole::WITHHELD_BY_PAYER, 'withholding', 'irrf'],
            'contribuicao previdenciaria due' => ['cp', FiscalTaxRole::PROVIDER_DUE, 'normal', 'cp'],
            'IBS is not a traditional federal invoice tax' => ['ibs', FiscalTaxRole::PROVIDER_DUE, 'normal', null],
            'CBS is not a traditional federal invoice tax' => ['cbs', FiscalTaxRole::PROVIDER_DUE, 'normal', null],
            'ISSQN is not a federal invoice tax' => ['issqn', FiscalTaxRole::PROVIDER_DUE, 'normal', null],
        ];
    }

    #[DataProvider('unverifiableFactCases')]
    public function testIncompleteOrUnsafeTaxFactsAreNotSilentlyAccepted(FiscalTaxFact $fact): void
    {
        $this->expectException(\DomainException::class);
        (new FederalTaxFactAggregator())->aggregate([$fact]);
    }

    /**
     * @return array<string,array{0:FiscalTaxFact}>
     */
    public static function unverifiableFactCases(): array
    {
        return [
            'unclassified tax ID despite an accounting name' => [new FiscalTaxFact(101, 7, 5001, 'normal', 500)],
            'missing item reference' => [new FiscalTaxFact(null, 7, 5001, 'normal', 500, 'pis', FiscalTaxRole::PROVIDER_DUE)],
            'missing tax row identity' => [new FiscalTaxFact(101, 7, null, 'normal', 500, 'pis', FiscalTaxRole::PROVIDER_DUE)],
            'missing observed amount is not zero' => [new FiscalTaxFact(101, 7, 5001, 'normal', null, 'pis', FiscalTaxRole::PROVIDER_DUE)],
            'negative amount requires separate legal assessment' => [new FiscalTaxFact(101, 7, 5001, 'normal', -50, 'pis', FiscalTaxRole::PROVIDER_DUE)],
        ];
    }

    public function testConflictingFactsWithTheSamePersistenceIdRequireReview(): void
    {
        $first = self::fact();
        $differentRate = self::fact(rate: '0.70');

        $this->expectException(\DomainException::class);
        (new FederalTaxFactAggregator())->aggregate([$first, $differentRate]);
    }

    public function testOneRatePerTaxDoesNotMeanTwoDistinctTaxesHaveOneLegalRate(): void
    {
        $result = (new FederalTaxFactAggregator())->aggregate([
            self::fact(rowId: 5001, taxId: 7),
            self::fact(rowId: 5002, taxId: 8),
        ]);

        self::assertSame(1000, $result['due']['pis']);
        self::assertSame('', $result['rates']['pis']);
    }

    public function testActualWithholdingNeverContributesToProviderDueRate(): void
    {
        $result = (new FederalTaxFactAggregator())->aggregate([
            self::fact(accountingType: 'withholding', role: FiscalTaxRole::WITHHELD_BY_PAYER),
        ]);

        self::assertSame(500, $result['retained']['pis']);
        self::assertSame('', $result['rates']['pis']);
    }

    private static function fact(
        int $itemId = 101,
        int $taxId = 7,
        int $rowId = 5001,
        string $accountingType = 'normal',
        int $cents = 500,
        ?string $identity = 'pis',
        ?FiscalTaxRole $role = FiscalTaxRole::PROVIDER_DUE,
        ?string $rate = '0.65',
    ): FiscalTaxFact {
        return new FiscalTaxFact(
            documentItemId: $itemId,
            taxId: $taxId,
            documentItemTaxRowId: $rowId,
            accountingType: $accountingType,
            amountCents: $cents,
            fiscalTaxCode: $identity,
            fiscalRole: $role,
            origin: 'synthetic-akaunting-tax-row',
            ratePercent: $rate,
        );
    }
}
