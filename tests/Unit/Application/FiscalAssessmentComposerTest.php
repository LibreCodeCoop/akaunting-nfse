<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use DateTimeImmutable;
use Modules\Nfse\Application\FiscalAssessmentComposer;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalTaxFact;
use PHPUnit\Framework\TestCase;

final class FiscalAssessmentComposerTest extends TestCase
{
    public function testUnknownEventIsNotInferredFromDeclaredCompetence(): void
    {
        $context = self::context(
            event: null,
            competence: '2026-11-01',
        );

        $assessment = (new FiscalAssessmentComposer())->assess($context);

        self::assertSame($context, $assessment->context);
        self::assertSame('unverifiable', $assessment->decisions[0]->status);
        self::assertSame('taxable_event_date_unverifiable', $assessment->decisions[0]->correctionCode);
    }

    public function testEventBeforeObligationIsNotReclassifiedByLaterCommercialDate(): void
    {
        $assessment = (new FiscalAssessmentComposer())->assess(self::context(
            event: '2026-09-30',
            competence: '2026-11-01',
        ));

        self::assertSame('not_applicable', $assessment->decisions[0]->status);
        self::assertSame([], (new FiscalAssessmentComposer())->blockingDecisions($assessment));
    }

    public function testTaxAssociationDiagnosticsRetainItemIdentityAndAreBlocking(): void
    {
        $context = self::context(
            event: '2026-11-02',
            competence: '2026-09-15',
            taxes: [new FiscalTaxFact(
                documentItemId: 91,
                taxId: 22,
                documentItemTaxRowId: 87,
                accountingType: 'normal',
                amountCents: 1234,
            )],
        );

        $composer = new FiscalAssessmentComposer();
        $assessment = $composer->assess($context);
        $blocked = $composer->blockingDecisions($assessment);

        self::assertCount(2, $assessment->decisions);
        self::assertCount(1, $blocked);
        self::assertSame('NFSE-TAX-ASSOCIATION-MISSING', $blocked[0]->ruleId);
        self::assertSame('document_item_taxes.87', $blocked[0]->fieldPath);
        self::assertSame(1234, $blocked[0]->observedCents);
    }

    public function testGroupAndCompanyIdentityRemainDistinct(): void
    {
        $composer = new FiscalAssessmentComposer();
        $first = $composer->assess(self::context(
            event: '2026-11-01',
            competence: '2026-11-01',
            companyId: 31,
            group: 'alpha',
        ));
        $second = $composer->assess(self::context(
            event: null,
            competence: '2026-11-01',
            companyId: 32,
            group: 'beta',
        ));

        self::assertSame(31, $first->context->companyId);
        self::assertSame('alpha', $first->context->fiscalGroupKey);
        self::assertSame(32, $second->context->companyId);
        self::assertSame('beta', $second->context->fiscalGroupKey);
        self::assertSame('unverifiable', $second->decisions[0]->status);
    }

    /** @param list<FiscalTaxFact>|null $taxes */
    private static function context(
        ?string $event,
        ?string $competence,
        ?array $taxes = null,
        int $companyId = 31,
        string $group = 'group-a',
    ): FiscalContext {
        return new FiscalContext(
            companyId: $companyId,
            fiscalGroupKey: $group,
            taxableEventDate: $event === null ? null : new DateTimeImmutable($event),
            competenceDate: $competence === null ? null : new DateTimeImmutable($competence),
            providerRegime: null,
            operationCategory: 'ordinary_lc116',
            lc116Subitem: '0107',
            nationalTaxCode: null,
            nbsCode: null,
            incidenceMunicipalityIbge: '3304557',
            takerType: null,
            takerMunicipalityIbge: null,
            takerCountryIso2: null,
            takerIsSimples: null,
            grossServiceCents: 10000,
            taxFacts: $taxes,
            opcaoSimplesNacional: 1,
        );
    }
}
