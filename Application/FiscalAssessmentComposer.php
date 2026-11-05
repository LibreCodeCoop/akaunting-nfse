<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;

/**
 * Composes independently owned fiscal policies without changing accounting
 * documents, inferring a taxable event or performing an issuance.
 */
final class FiscalAssessmentComposer
{
    public function __construct(
        private readonly RtcSupplyAssessmentPolicy $rtc = new RtcSupplyAssessmentPolicy(),
        private readonly FiscalTaxAssociationDiagnostics $taxes = new FiscalTaxAssociationDiagnostics(),
    ) {
    }

    public function assess(FiscalContext $context): FiscalAssessment
    {
        $decisions = $this->rtc->assess($context)->decisions;

        foreach ($this->taxes->inspect($context->taxFacts ?? []) as $decision) {
            $decisions[] = $decision;
        }

        return new FiscalAssessment($context, $decisions);
    }

    /**
     * @return list<FiscalDecision>
     */
    public function blockingDecisions(FiscalAssessment $assessment): array
    {
        return array_values(array_filter(
            $assessment->decisions,
            static fn (FiscalDecision $decision): bool => $decision->severity === 'block',
        ));
    }
}
