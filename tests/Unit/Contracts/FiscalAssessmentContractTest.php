<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Contracts\FiscalGovernmentSphere;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\TestCase;

final class FiscalAssessmentContractTest extends TestCase
{
    public function testFactsKeepCompanyLineIdentityMoneyAndTaxableEventDistinct(): void
    {
        $context = $this->context([
            new FiscalTaxFact(1001, 9, 7, 'withholding', 3250, 'PIS', FiscalTaxRole::WITHHELD_BY_TAKER, 'document_item_taxes', 500000, '0.65'),
            new FiscalTaxFact(1002, 9, 8, 'withholding', 3250, 'PIS', FiscalTaxRole::WITHHELD_BY_TAKER, 'document_item_taxes', 500000, '0.65'),
        ]);

        self::assertSame(31, $context->companyId);
        self::assertSame(1000000, $context->grossServiceCents);
        self::assertSame('2026-09-30', $context->taxableEventDate->format('Y-m-d'));
        self::assertSame('2026-10-01', $context->competenceDate->format('Y-m-d'));
        self::assertCount(2, $context->taxFacts);
        self::assertSame(6500, $context->taxFacts[0]->amountCents + $context->taxFacts[1]->amountCents);
        self::assertSame(7, $context->taxFacts[0]->documentItemTaxRowId);
        self::assertSame(8, $context->taxFacts[1]->documentItemTaxRowId);
        self::assertSame('document_item_taxes', $context->taxFacts[1]->origin);
        self::assertSame('0.65', $context->taxFacts[1]->ratePercent);
        self::assertSame(FiscalTaxRole::WITHHELD_BY_TAKER, $context->taxFacts[1]->fiscalRole);
    }

    public function testLegalFormAndPaymentFactsRemainDistinctFromTaxRegimeAndServiceAmount(): void
    {
        $context = new FiscalContext(
            31,
            'synthetic-group-1',
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
            'lucro_real',
            'ordinary_lc116',
            '01.01',
            '010101',
            null,
            '3304557',
            'legal_entity',
            '3304557',
            'BR',
            false,
            1000000,
            [],
            true,
            20000,
            new DateTimeImmutable('2026-10-15'),
        );

        self::assertTrue($context->providerIsCooperative);
        self::assertSame(20000, $context->paymentAmountCents);
        self::assertSame(1000000, $context->grossServiceCents);
        self::assertSame('2026-10-15', $context->paymentDate?->format('Y-m-d'));

        // R$200 actually paid cannot be silently replaced with R$10,000 invoiced.
        // Rule applicability (including the payment-value exemption) belongs to #428/#432.
    }

    public function testUnknownTaxIdentityIsNotGuessedFromAccountingType(): void
    {
        $tax = new FiscalTaxFact(1001, null, null, 'withholding', 125, null, null);

        self::assertNull($tax->taxId);
        self::assertNull($tax->fiscalTaxCode);
        self::assertNull($tax->fiscalRole);
        self::assertNull($tax->origin);
        self::assertNull($tax->baseCents);
        self::assertNull($tax->ratePercent);
    }

    public function testMissingSpecialRegimeAndExplicitZeroRemainDistinct(): void
    {
        $unknown = $this->context([]);
        self::assertNull($unknown->regimeEspecialTributacao);
        self::assertNull($unknown->opcaoSimplesNacional);
        self::assertNull($unknown->regimeApuracaoTributariaSn);
        self::assertNull($unknown->ibsCbsRegime);
        self::assertNull($unknown->takerGovernmentSphere);
        self::assertNull($unknown->takerLegalNatureCode);

        $known = new FiscalContext(
            31,
            'synthetic-special-regime',
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
            'simples',
            'ordinary_lc116',
            '01.01',
            '010101',
            null,
            '3304557',
            'legal_entity',
            '3304557',
            'BR',
            null,
            1000000,
            [],
            false,
            0,
            null,
            3,
            1,
            0,
            'simples',
            FiscalGovernmentSphere::MUNICIPAL,
            '1031',
        );

        self::assertSame(0, $known->regimeEspecialTributacao);
        self::assertSame(0, $known->paymentAmountCents);
        self::assertSame(3, $known->opcaoSimplesNacional);
        self::assertSame(1, $known->regimeApuracaoTributariaSn);
        self::assertSame('simples', $known->ibsCbsRegime);
        self::assertSame(FiscalGovernmentSphere::MUNICIPAL, $known->takerGovernmentSphere);
        self::assertSame('1031', $known->takerLegalNatureCode);
    }

    public function testUnknownTaxAmountDoesNotBecomeAnObservedZero(): void
    {
        $unknown = new FiscalTaxFact(1001, 9, 7, 'normal', null);
        $observedZero = new FiscalTaxFact(1001, 9, 7, 'normal', 0);

        self::assertNull($unknown->amountCents);
        self::assertSame(0, $observedZero->amountCents);
    }

    public function testTakerPublicNatureRequiresSourceDataRatherThanBooleanGuessing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FiscalContext(
            companyId: 31,
            fiscalGroupKey: 'synthetic-public-taker',
            taxableEventDate: new DateTimeImmutable('2026-10-01'),
            competenceDate: new DateTimeImmutable('2026-10-01'),
            providerRegime: 'lucro_presumido',
            operationCategory: 'ordinary_lc116',
            lc116Subitem: '01.01',
            nationalTaxCode: '010101',
            nbsCode: null,
            incidenceMunicipalityIbge: '3304557',
            takerType: 'legal_entity',
            takerMunicipalityIbge: '3304557',
            takerCountryIso2: 'BR',
            takerIsSimples: null,
            grossServiceCents: 1000000,
            takerGovernmentSphere: FiscalGovernmentSphere::FEDERAL,
            takerLegalNatureCode: 'unknown',
        );
    }

    public function testRejectsUnverifiableTaxRateInsteadOfCoercingToFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FiscalTaxFact(
            1001,
            9,
            7,
            'withholding',
            3250,
            'PIS',
            FiscalTaxRole::WITHHELD_BY_TAKER,
            'document_item_taxes',
            500000,
            '0,65',
        );
    }

    public function testAcceptsAnObservedRealZeroTaxRate(): void
    {
        $fact = new FiscalTaxFact(
            1001,
            9,
            7,
            'normal',
            0,
            'PIS',
            FiscalTaxRole::PROVIDER_DUE,
            'document_item_taxes',
            1000000,
            '0.00',
        );

        self::assertSame(0, $fact->amountCents);
        self::assertSame('0.00', $fact->ratePercent);
    }

    public function testSourceBackedUnverifiableDecisionDoesNotMeanApproval(): void
    {
        $decision = new FiscalDecision(
            'RTC-ISS-001',
            'normative',
            'unverifiable',
            'warn',
            'https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm',
            'art. 3',
            'LC 116/2003',
            new DateTimeImmutable('2003-07-31'),
            new DateTimeImmutable('2003-08-01'),
            null,
            'incidenceMunicipalityIbge',
            'review_municipal_incidence',
        );

        $assessment = new FiscalAssessment($this->context([]), [$decision]);

        self::assertSame('unverifiable', $assessment->decisions[0]->status);
        self::assertSame('review_municipal_incidence', $assessment->decisions[0]->correctionCode);
    }

    public function testInvalidTemporalScopeFailsInsteadOfSelectingAnotherRule(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FiscalDecision(
            'RTC-OBL-001',
            'normative',
            'pass',
            'info',
            'https://hom.cte.fazenda.gov.br/portal/informe.aspx?informe=98',
            'art. 1 III d',
            'Ato Conjunto RFB/CGIBS 4/2026',
            new DateTimeImmutable('2026-07-30'),
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-09-30'),
            null,
            null,
        );
    }

    /**
     * @param list<FiscalTaxFact> $facts
     */
    private function context(array $facts): FiscalContext
    {
        return new FiscalContext(
            31,
            'synthetic-group-1',
            new DateTimeImmutable('2026-09-30'),
            new DateTimeImmutable('2026-10-01'),
            'lucro_presumido',
            'ordinary_lc116',
            '01.01',
            '010101',
            null,
            '3304557',
            'legal_entity',
            '3304557',
            'BR',
            false,
            1000000,
            $facts,
        );
    }
}
