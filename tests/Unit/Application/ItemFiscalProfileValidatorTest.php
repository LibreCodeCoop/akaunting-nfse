<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ItemFiscalProfileValidator;
use PHPUnit\Framework\TestCase;

final class ItemFiscalProfileValidatorTest extends TestCase
{
    public function testKnownCodesAreNormativelyValidWithoutInventingCorrelationRule(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate('1.07', '010701');

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['issues']);
        self::assertSame('unverifiable', $result['correlation_status']);
        self::assertStringContainsString('ANEXO_B', $result['source_versions']['service_nbs']);
    }

    public function testValidNationalConstructionServiceMustNotBeRejectedByPartialLc116Catalog(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate('7.02', '070201');

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['issues']);
    }

    public function testUnknownNationalServiceCodeIsInvalid(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate('0107', '999999');

        self::assertSame('invalid', $result['status']);
        self::assertContains('invalid_national_code', $result['issues']);
    }

    public function testUnlistedWellFormedLc116CodeIsNotPresumedInvalid(): void
    {
        // LC 116 item 5.09 is not in the module's curated picker.
        // Do not infer any LC116/cTribNac correspondence from this test.
        $result = (new ItemFiscalProfileValidator())->validate('05.09', '010701');

        self::assertSame('warning', $result['status']);
        self::assertContains('unverified_service_code', $result['issues']);
        self::assertNotContains('invalid_service_code', $result['issues']);
    }

    public function testMalformedLc116CodeIsInvalid(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate('invalid-value', '010701');

        self::assertSame('invalid', $result['status']);
        self::assertContains('invalid_service_code', $result['issues']);
    }

    public function testIncompleteProfileIsWarningInsteadOfGuessed(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate('0107', null);

        self::assertSame('warning', $result['status']);
        self::assertContains('missing_national_code', $result['issues']);
    }

    public function testEmptyProfileIsUnverifiable(): void
    {
        $result = (new ItemFiscalProfileValidator())->validate(null, null);

        self::assertSame('unverifiable', $result['status']);
        self::assertSame(['missing_profile'], $result['issues']);
    }
}
