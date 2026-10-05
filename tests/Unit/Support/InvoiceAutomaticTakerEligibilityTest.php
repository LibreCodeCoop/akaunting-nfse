<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use App\Models\Document\Document;
use Modules\Nfse\Support\InvoiceAutomaticTakerEligibility;
use PHPUnit\Framework\TestCase;

final class InvoiceAutomaticTakerEligibilityTest extends TestCase
{
    public function testBrazilOrUnknownCountryCanContinueAutomaticPreflight(): void
    {
        $resolver = new InvoiceAutomaticTakerEligibility();

        $unknown = new Document();
        self::assertTrue($resolver->evaluate($unknown)['eligible']);

        $brazil = new Document();
        $brazil->contact_country_code = 'BR';
        self::assertTrue($resolver->evaluate($brazil)['eligible']);
    }

    public function testForeignCountryRequiresInteractiveReview(): void
    {
        $invoice = new Document();
        $invoice->contact_country_code = 'GB';

        $result = (new InvoiceAutomaticTakerEligibility())->evaluate($invoice);

        self::assertFalse($result['eligible']);
        self::assertSame('foreign_taker_requires_review', $result['reason']);
        self::assertSame(['GB'], $result['details']);
    }
}
