<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FiscalTaxAssociationDiagnostics;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\TestCase;

final class FiscalTaxAssociationDiagnosticsTest extends TestCase
{
    public function testUnclassifiedHomonymousTaxNeverBecomesFiscalPIS(): void
    {
        $fact = new FiscalTaxFact(101, 7, 5001, 'normal', 500, null, null, 'synthetic', null, '5.00');
        $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$fact]);

        self::assertCount(1, $decisions);
        self::assertSame('NFSE-TAX-ASSOCIATION-MISSING', $decisions[0]->ruleId);
        self::assertSame('unverifiable', $decisions[0]->status);
        self::assertSame('block', $decisions[0]->severity);
    }

    public function testRetainedVersusDueAccountingMismatchRequiresReview(): void
    {
        $due = new FiscalTaxFact(101, 7, 5001, 'withholding', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
        $withheld = new FiscalTaxFact(102, 8, 5002, 'normal', 500, 'pis', FiscalTaxRole::WITHHELD_BY_PAYER);
        $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$due, $withheld]);

        self::assertCount(2, $decisions);
        self::assertSame('NFSE-TAX-TYPE-ROLE-REVIEW', $decisions[0]->ruleId);
        self::assertSame('NFSE-TAX-TYPE-ROLE-REVIEW', $decisions[1]->ruleId);
    }

    public function testInclusiveCompoundAndFixedCannotSilentlyBecomeLegalBase(): void
    {
        foreach (['inclusive', 'compound', 'fixed'] as $type) {
            $fact = new FiscalTaxFact(101, 7, 5001, $type, 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
            $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$fact]);
            self::assertSame('NFSE-TAX-BASE-AND-TYPE-REVIEW', $decisions[0]->ruleId);
        }
    }

    public function testConfirmedIdentityAloneDoesNotClaimNormativeCompliance(): void
    {
        $fact = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
        self::assertSame([], (new FiscalTaxAssociationDiagnostics())->inspect([$fact]));
    }

    public function testEstimatedTaxCannotBeConvertedToRegularInvoiceTax(): void
    {
        $fact = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'cbs', FiscalTaxRole::ESTIMATED_DISCLOSURE);
        $decisions = (new FiscalTaxAssociationDiagnostics())->inspect([$fact]);
        self::assertSame('NFSE-TAX-ESTIMATE-NOT-ACCOUNTING', $decisions[0]->ruleId);
    }
}
