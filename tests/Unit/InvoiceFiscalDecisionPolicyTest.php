<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit;

use Modules\Nfse\Application\InvoiceFiscalDecisionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceFiscalDecisionPolicyTest extends TestCase
{
    /** @return iterable<string,array{string,array<string>,bool}> */
    public static function receiptBoundaryCases(): iterable
    {
        yield 'authorized' => ['not_applicable', ['emitted'], false];
        yield 'pending' => ['not_applicable', ['pending'], false];
        yield 'processing' => ['do_not_issue', ['processing'], false];
        yield 'authorized among canceled' => ['do_not_issue', ['cancelled', 'emitted'], false];
        yield 'cancelled only' => ['do_not_issue', ['cancelled'], true];
        yield 'substituted only' => ['not_applicable', ['substituted'], true];
        yield 'empty' => ['not_applicable', [], true];
        yield 'reversed' => ['reversed', ['emitted'], true];
    }

    #[DataProvider('receiptBoundaryCases')]
    public function testFiscalReceiptBoundary(string $decision, array $statuses, bool $allowed): void
    {
        self::assertSame($allowed, (new InvoiceFiscalDecisionPolicy())->mayClassify($decision, $statuses));
    }

    public function testValidReceiptWinsPresentationWhileMissingReceiptsRemainUnverified(): void
    {
        $policy = new InvoiceFiscalDecisionPolicy();
        self::assertSame('emitted', $policy->effectiveDisplayStatus('do_not_issue', 'emitted'));
        self::assertSame('cancelled', $policy->effectiveDisplayStatus('not_applicable', 'cancelled'));
        self::assertSame('do_not_issue', $policy->effectiveDisplayStatus('do_not_issue', null));
        self::assertSame('pending', $policy->effectiveDisplayStatus('reversed', null));
    }
}
