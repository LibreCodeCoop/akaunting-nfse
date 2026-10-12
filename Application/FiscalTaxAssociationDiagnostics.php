<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;

/**
 * Structural diagnostics only: no accounting tax type proves legal incidence.
 * Normative permission and monetary assessment belong to versioned policies.
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
            $reason = null;

            if ($fact->taxId === null || $fact->fiscalTaxCode === null || $fact->fiscalRole === null) {
                $reason = 'NFSE-TAX-ASSOCIATION-MISSING';
            } elseif ($fact->fiscalRole === FiscalTaxRole::ESTIMATED_DISCLOSURE) {
                $reason = 'NFSE-TAX-ESTIMATE-NOT-ACCOUNTING';
            } elseif (($fact->accountingType === 'withholding') !== ($fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER)) {
                $reason = 'NFSE-TAX-TYPE-ROLE-REVIEW';
            } elseif (!in_array($fact->accountingType, ['normal', 'withholding'], true)) {
                $reason = 'NFSE-TAX-BASE-AND-TYPE-REVIEW';
            }

            if ($reason === null) {
                continue;
            }

            $decisions[] = new FiscalDecision(
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

        return $decisions;
    }
}
