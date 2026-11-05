<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use DateTimeImmutable;
use Modules\Nfse\Application\FinalDpsFederalEvidenceValidator;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinalDpsFederalEvidenceValidatorTest extends TestCase
{
    public function testNt007RetainedPisAndCofinsWithoutCsllIsValidWhenProven(): void
    {
        $decisions = (new FinalDpsFederalEvidenceValidator())->inspect(
            self::context(self::retainedSocialFacts()),
            self::dps(['federalPiscofinsTipoRetencao' => '4', 'federalValorCsll' => '365.00']),
        );

        self::assertSame([], $decisions);
    }

    #[DataProvider('inconsistentDps')]
    public function testFinalPayloadCannotHideOrInventRetainedAmounts(
        array $changes,
        string $rule,
        ?int $expected,
        ?int $observed,
    ): void {
        $decisions = (new FinalDpsFederalEvidenceValidator())->inspect(
            self::context(self::retainedSocialFacts()),
            self::dps($changes),
        );

        self::assertNotEmpty($decisions);
        self::assertSame($rule, $decisions[0]->ruleId);
        self::assertSame('block', $decisions[0]->severity);
        self::assertSame($expected, $decisions[0]->expectedCents);
        self::assertSame($observed, $decisions[0]->observedCents);
    }

    /** @return array<string,array{array<string,string>,string,?int,?int}> */
    public static function inconsistentDps(): array
    {
        return [
            'fake zero vRetCSLL' => [
                ['federalPiscofinsTipoRetencao' => '4', 'federalValorCsll' => '0.00'],
                'NFSE-NT007-OBSERVATION-MISMATCH', 36500, 0,
            ],
            'missing vRetCSLL' => [
                ['federalPiscofinsTipoRetencao' => '4', 'federalValorCsll' => ''],
                'NFSE-NT007-OBSERVATION-MISMATCH', 36500, null,
            ],
            'unselected retained PIS COFINS' => [
                ['federalPiscofinsTipoRetencao' => '', 'federalValorCsll' => ''],
                'NFSE-TAX-FACTS-UNVERIFIABLE', null, null,
            ],
            'invented standalone CSLL' => [
                ['federalPiscofinsTipoRetencao' => '4', 'federalValorCsll' => '500.00'],
                'NFSE-NT007-OBSERVATION-MISMATCH', 36500, 50000,
            ],
        ];
    }

    public function testUnknownRetentionTypeCannotPassThroughPermissiveTransport(): void
    {
        $decisions = (new FinalDpsFederalEvidenceValidator())->inspect(
            self::context([]),
            self::dps(['federalPiscofinsTipoRetencao' => '99']),
        );

        self::assertSame('NFSE-NT007-RETENTION-TYPE-UNSUPPORTED', $decisions[0]->ruleId);
        self::assertSame('block', $decisions[0]->severity);
    }

    public function testOwnPisWithoutProvenBaseCannotPassFinalBoundary(): void
    {
        $facts = [new FiscalTaxFact(91, 12, 301, 'normal', 2000, 'pis', FiscalTaxRole::PROVIDER_DUE, 'akaunting.document_item_taxes', null, '2.00')];
        $decisions = (new FinalDpsFederalEvidenceValidator())->inspect(
            self::context($facts),
            self::dps(['federalPiscofinsValorPis' => '20.00']),
        );

        self::assertSame('NFSE-TAX-BASE-OR-RATE-UNVERIFIABLE', $decisions[0]->ruleId);
    }

    public function testEmptyConfirmedRowsCannotJustifyInventedFederalValues(): void
    {
        $decisions = (new FinalDpsFederalEvidenceValidator())->inspect(
            self::context([]),
            self::dps(['federalValorCsll' => '0.00']),
        );

        self::assertSame('NFSE-NT007-OBSERVATION-MISMATCH', $decisions[0]->ruleId);
        self::assertNull($decisions[0]->expectedCents);
        self::assertSame(0, $decisions[0]->observedCents);
    }

    /** @return list<FiscalTaxFact> */
    private static function retainedSocialFacts(): array
    {
        return [
            new FiscalTaxFact(91, 10, 101, 'withholding', 6500, 'pis', FiscalTaxRole::WITHHELD_BY_PAYER),
            new FiscalTaxFact(91, 11, 102, 'withholding', 30000, 'cofins', FiscalTaxRole::WITHHELD_BY_PAYER),
        ];
    }

    /** @param list<FiscalTaxFact> $facts */
    private static function context(array $facts): FiscalContext
    {
        return new FiscalContext(
            companyId: 31,
            fiscalGroupKey: 'group-a',
            taxableEventDate: new DateTimeImmutable('2026-11-01'),
            competenceDate: new DateTimeImmutable('2026-11-01'),
            providerRegime: null,
            operationCategory: 'ordinary_lc116',
            lc116Subitem: '0107',
            nationalTaxCode: '010701',
            nbsCode: null,
            incidenceMunicipalityIbge: null,
            takerType: null,
            takerMunicipalityIbge: null,
            takerCountryIso2: null,
            takerIsSimples: null,
            grossServiceCents: 10000,
            taxFacts: $facts,
            opcaoSimplesNacional: 1,
        );
    }

    /** @param array<string,mixed> $changes */
    private static function dps(array $changes): DpsData
    {
        return new DpsData(...array_replace([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3304557',
            'itemListaServico' => '0107',
            'codigoTributacaoNacional' => '010701',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Synthetic service',
            'dataCompetencia' => '2026-11-01',
        ], $changes));
    }
}
