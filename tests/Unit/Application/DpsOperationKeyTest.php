<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\DpsOperationKey;
use PHPUnit\Framework\TestCase;

final class DpsOperationKeyTest extends TestCase
{
    public function testCallerOriginCannotChangeTheFiscalOperationIdentity(): void
    {
        $http = DpsOperationKey::forInvoice(7, 42, 'service:0107');
        $worker = DpsOperationKey::forInvoice(7, 42, 'service:0107');

        self::assertSame($http, $worker);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $http);
        self::assertNotSame($http, DpsOperationKey::forInvoice(8, 42, 'service:0107'));
        self::assertNotSame($http, DpsOperationKey::forInvoice(7, 43, 'service:0107'));
        self::assertNotSame($http, DpsOperationKey::forInvoice(7, 42, 'service:0101'));
    }

    public function testSubstitutionAndReissuanceNeverShareOriginalKey(): void
    {
        $base = DpsOperationKey::forInvoice(7, 42);
        $reissue = DpsOperationKey::forInvoice(7, 42, cancelledReceiptId: 50);
        $replacement = DpsOperationKey::forInvoice(7, 42, substitutedReceiptId: 50);

        self::assertNotSame($base, $reissue);
        self::assertNotSame($reissue, $replacement);
        self::assertNotSame($reissue, DpsOperationKey::forInvoice(7, 42, cancelledReceiptId: 51));
    }

    public function testCannotCombineDifferentFiscalOperations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DpsOperationKey::forInvoice(7, 42, cancelledReceiptId: 50, substitutedReceiptId: 50);
    }
}
