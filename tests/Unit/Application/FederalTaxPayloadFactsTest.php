<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalTaxPayloadFacts;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalTaxPayloadFactsTest extends TestCase
{
    /**
     * @param list<array{string,FiscalTaxRole,int,int,int}> $rows
     */
    #[DataProvider('nt007Cases')]
    public function testKnownFederalFactsRespectNt007AndPersistedOccurrence(
        string $type,
        array $rows,
        string $pis,
        string $cofins,
        string $social,
        string $irrf,
    ): void {
        $facts = [];
        foreach ($rows as [$code, $role, $cents, $tax, $row]) {
            $facts[] = self::fact($code, $role, $cents, $tax, $row);
        }

        $actual = (new FederalTaxPayloadFacts())->summarize($facts, $type);

        self::assertSame($pis, $actual['pis_value']);
        self::assertSame($cofins, $actual['cofins_value']);
        self::assertSame($social, $actual['social_total']);
        self::assertSame($irrf, $actual['irrf_value']);
        self::assertSame('', $actual['base_value'], 'The commercial invoice gross is not the proven tax base.');
        self::assertSame('', $actual['pis_rate'], 'A rate without a proven base must not enter the XML.');
    }

    /** @return iterable<string,array{string,list<array{string,FiscalTaxRole,int,int,int}>,string,string,string,string}> */
    public static function nt007Cases(): iterable
    {
        $due = FiscalTaxRole::PROVIDER_DUE;
        $ret = FiscalTaxRole::WITHHELD_BY_PAYER;

        yield 'type four with PIS and COFINS withheld, no CSLL' => [
            '4', [
                ['pis', $ret, 6500, 10, 100], ['cofins', $ret, 30000, 11, 101],
                ['pis', $due, 16500, 12, 102], ['cofins', $due, 76000, 13, 103],
            ], '165.00', '760.00', '365.00', '',
        ];
        yield 'type three retains PIS COFINS CSLL, not IRRF' => [
            '3', [
                ['pis', $ret, 6500, 10, 100], ['cofins', $ret, 30000, 11, 101],
                ['csll', $ret, 10000, 12, 102], ['irrf', $ret, 15000, 13, 103],
            ], '', '', '465.00', '150.00',
        ];
        yield 'equal monetary observations on distinct rows both count' => [
            '0', [['pis', $due, 500, 10, 100], ['pis', $due, 500, 10, 101]],
            '10.00', '', '', '',
        ];
        yield 'same persisted row twice counts once' => [
            '0', [['pis', $due, 500, 10, 100], ['pis', $due, 500, 10, 100]],
            '5.00', '', '', '',
        ];
        yield 'IBS and CBS cannot become ordinary federal taxes' => [
            '0', [['ibs', $due, 100, 10, 100], ['cbs', $due, 900, 11, 101]],
            '', '', '', '',
        ];
        yield 'confirmed zero remains distinguishable from no row' => [
            '0', [['pis', $due, 0, 10, 100]], '0.00', '', '', '',
        ];
    }

    public function testUnclassifiedObservationDoesNotBecomeAnInferredTax(): void
    {
        $this->expectException(\DomainException::class);
        (new FederalTaxPayloadFacts())->summarize([
            new FiscalTaxFact(101, 10, 100, 'normal', 6500),
        ], '0');
    }

    public function testIncompatibleRetentionCodeRejectsWithholdingInsteadOfDroppingIt(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RETENTION-TYPE-CONFLICT');
        (new FederalTaxPayloadFacts())->summarize([
            self::fact('csll', FiscalTaxRole::WITHHELD_BY_PAYER, 10000, 10, 100),
        ], '4');
    }

    public function testTwoTaxIdsWithEqualRatesHaveNoUnprovenCombinedRate(): void
    {
        $result = (new FederalTaxPayloadFacts())->summarize([
            self::fact('pis', FiscalTaxRole::PROVIDER_DUE, 500, 10, 100, 10000, '5.00'),
            self::fact('pis', FiscalTaxRole::PROVIDER_DUE, 500, 11, 101, 10000, '5.00'),
        ], '0');

        self::assertSame('10.00', $result['pis_value']);
        self::assertSame('100.00', $result['base_value']);
        self::assertSame('', $result['pis_rate']);
    }

    private static function fact(
        string $code,
        FiscalTaxRole $role,
        int $amount,
        int $taxId,
        int $rowId,
        ?int $baseCents = null,
        ?string $rate = null,
    ): FiscalTaxFact {
        return new FiscalTaxFact(
            documentItemId: 101,
            taxId: $taxId,
            documentItemTaxRowId: $rowId,
            accountingType: $role === FiscalTaxRole::PROVIDER_DUE ? 'normal' : 'withholding',
            amountCents: $amount,
            fiscalTaxCode: $code,
            fiscalRole: $role,
            origin: 'synthetic.document_item_taxes',
            baseCents: $baseCents,
            ratePercent: $rate,
        );
    }
}
