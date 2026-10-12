<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FiscalTaxAssociationDiagnostics;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FiscalTaxAssociationDiagnosticsTest extends TestCase
{
    #[DataProvider('fiscalEvidenceDecisions')]
    public function testStructuralEvidenceDoesNotBecomeAnUnjustifiedLegalApproval(
        string $accountingType,
        ?string $identity,
        ?FiscalTaxRole $role,
        ?string $expectedReason,
    ): void {
        $fact = new FiscalTaxFact(101, 7, 5001, $accountingType, 500, $identity, $role);
        $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$fact]);

        if ($expectedReason === null) {
            // No structural finding is not a determination of legal incidence.
            self::assertSame([], $decisions);

            return;
        }

        self::assertCount(1, $decisions);
        self::assertSame($expectedReason, $decisions[0]->ruleId);
        self::assertSame('unverifiable', $decisions[0]->status);
        self::assertSame('block', $decisions[0]->severity);
        self::assertSame('document_item_taxes.5001', $decisions[0]->fieldPath);
    }

    /**
     * @return array<string,array{0:string,1:?string,2:?FiscalTaxRole,3:?string}>
     */
    public static function fiscalEvidenceDecisions(): array
    {
        return [
            'homonymous PIS without tax ID confirmation is unknown' => [
                'normal', null, null, 'NFSE-TAX-ASSOCIATION-MISSING',
            ],
            'identity without confirmed fiscal role is insufficient' => [
                'normal', 'pis', null, 'NFSE-TAX-ASSOCIATION-MISSING',
            ],
            'estimated tax is not a billable invoice tax' => [
                'normal', 'cbs', FiscalTaxRole::ESTIMATED_DISCLOSURE, 'NFSE-TAX-ESTIMATE-NOT-ACCOUNTING',
            ],
            'withholding accounting type cannot be own due tax' => [
                'withholding', 'pis', FiscalTaxRole::PROVIDER_DUE, 'NFSE-TAX-TYPE-ROLE-REVIEW',
            ],
            'normal invoice tax cannot prove payment withholding' => [
                'normal', 'pis', FiscalTaxRole::WITHHELD_BY_PAYER, 'NFSE-TAX-TYPE-ROLE-REVIEW',
            ],
            'inclusive needs taxable base verification' => [
                'inclusive', 'pis', FiscalTaxRole::PROVIDER_DUE, 'NFSE-TAX-BASE-AND-TYPE-REVIEW',
            ],
            'compound requires tax ordering and base evidence' => [
                'compound', 'pis', FiscalTaxRole::PROVIDER_DUE, 'NFSE-TAX-BASE-AND-TYPE-REVIEW',
            ],
            'fixed amount cannot be silently interpreted as percent' => [
                'fixed', 'pis', FiscalTaxRole::PROVIDER_DUE, 'NFSE-TAX-BASE-AND-TYPE-REVIEW',
            ],
            'verified normal due tax has no structural contradiction' => [
                'normal', 'pis', FiscalTaxRole::PROVIDER_DUE, null,
            ],
            'verified retained tax has no structural contradiction' => [
                'withholding', 'pis', FiscalTaxRole::WITHHELD_BY_PAYER, null,
            ],
        ];
    }

    public function testMultipleTaxRowsRetainTheirOwnFindingsAndObservedAmounts(): void
    {
        $unknown = new FiscalTaxFact(101, 7, 5001, 'normal', 0, null, null);
        $incompatible = new FiscalTaxFact(102, 8, 5002, 'withholding', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);

        $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$unknown, $incompatible]);

        self::assertSame(
            ['NFSE-TAX-ASSOCIATION-MISSING', 'NFSE-TAX-TYPE-ROLE-REVIEW'],
            array_map(static fn ($decision): string => $decision->ruleId, $decisions),
        );
        self::assertSame(0, $decisions[0]->observedCents);
        self::assertSame(500, $decisions[1]->observedCents);
    }
}
