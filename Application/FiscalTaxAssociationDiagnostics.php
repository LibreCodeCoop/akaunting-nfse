<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;

/**
 * Structural diagnostics only: tax type and role do not prove incidence.
 * Versioned normative policies must independently establish legal treatment.
 */
final class FiscalTaxAssociationDiagnostics
{
    /**
     * @param list<FiscalTaxFact> $facts
     * @return list<FiscalDecision>
     */
    public function inspect(array $facts): array
    {
        $decisions = [];
        foreach ($facts as $fact) {
            $reason = $this->reasonFor($fact);
            if ($reason !== null) {
                $decisions[] = $this->blockedDecision($fact, $reason);
            }
        }

        return $decisions;
    }

    private function reasonFor(FiscalTaxFact $fact): ?string
    {
        if ($fact->taxId === null || $fact->fiscalTaxCode === null || $fact->fiscalRole === null) {
            return 'NFSE-TAX-ASSOCIATION-MISSING';
        }

        if ($fact->fiscalRole === FiscalTaxRole::ESTIMATED_DISCLOSURE) {
            return 'NFSE-TAX-ESTIMATE-NOT-ACCOUNTING';
        }

        if (($fact->accountingType === 'withholding') !== ($fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER)) {
            return 'NFSE-TAX-TYPE-ROLE-REVIEW';
        }

        if (!in_array($fact->accountingType, ['normal', 'withholding'], true)) {
            return 'NFSE-TAX-BASE-AND-TYPE-REVIEW';
        }

        return null;
    }

    private function blockedDecision(FiscalTaxFact $fact, string $reason): FiscalDecision
    {
        return new FiscalDecision(
            ruleId: $reason,
            category: 'document',
            status: 'unverifiable',
            severity: 'block',
            sourceUrl: null,
            sourceProvision: null,
            sourceVersion: null,
            sourcePublishedAt: null,
            effectiveFrom: null,
            effectiveUntil: null,
            fieldPath: 'document_item_taxes.' . ($fact->documentItemTaxRowId ?? 'unknown'),
            correctionCode: strtolower(str_replace('NFSE-TAX-', '', $reason)),
            observedCents: $fact->amountCents,
        );
    }
}
