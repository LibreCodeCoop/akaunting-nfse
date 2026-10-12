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
use Modules\Nfse\Contracts\FiscalTaxFact;
use PHPUnit\Framework\TestCase;

final class FiscalAssessmentContractTest extends TestCase
{
    public function testFactsKeepCompanyLineIdentityMoneyAndTaxableEventDistinct(): void
    {
        $context = $this->context([
            new FiscalTaxFact(1001, 9, 7, 'withholding', 6500, 'PIS', 'withheld'),
            new FiscalTaxFact(1002, 9, 8, 'withholding', 6500, 'PIS', 'withheld'),
        ]);

        self::assertSame(31, $context->companyId);
        self::assertSame(1000000, $context->grossServiceCents);
        self::assertSame('2026-09-30', $context->taxableEventDate->format('Y-m-d'));
        self::assertSame('2026-10-01', $context->competenceDate->format('Y-m-d'));
        self::assertCount(2, $context->taxFacts);
        self::assertSame(13000, $context->taxFacts[0]->amountCents + $context->taxFacts[1]->amountCents);
        self::assertSame(7, $context->taxFacts[0]->documentItemTaxRowId);
        self::assertSame(8, $context->taxFacts[1]->documentItemTaxRowId);
    }

    public function testLegalFormAndPaymentFactsRemainDistinctFromTaxRegimeAndServiceAmount(): void
    {
        $facts = [new FiscalTaxFact(1001, 9, 7, 'withholding', 930, 'PIS_COFINS_CSLL', 'withheld')];
        $context = new FiscalContext(
            31,
            'synthetic-group-1',
            new DateTimeImmutable('2026-10-01'),
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
