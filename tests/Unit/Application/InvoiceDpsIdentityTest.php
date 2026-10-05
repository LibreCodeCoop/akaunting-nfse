<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use App\Models\Document\Document;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use PHPUnit\Framework\TestCase;

final class InvoiceDpsIdentityTest extends TestCase
{
    public function testOrdinaryInvoiceIdentityIsStable(): void
    {
        $invoice = new Document();
        $invoice->id = 42;

        $identity = new InvoiceDpsIdentity();

        self::assertSame('00001', $identity->series($invoice));
        self::assertSame('42', $identity->number($invoice));
    }

    public function testCompetenceUsesIssuedAtWithoutInventingFallbackDate(): void
    {
        $identity = new InvoiceDpsIdentity();

        $dated = new Document();
        $dated->issued_at = '2026-10-05 10:30:00';
        self::assertSame('2026-10-05', $identity->competenceDate($dated));

        $undated = new Document();
        self::assertNull($identity->competenceDate($undated));
    }
}
