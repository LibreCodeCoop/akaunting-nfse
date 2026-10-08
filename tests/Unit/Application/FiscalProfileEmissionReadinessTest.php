<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FiscalProfileEmissionReadiness;
use PHPUnit\Framework\TestCase;

final class FiscalProfileEmissionReadinessTest extends TestCase
{
    public function testValidNormativeProfileIsReady(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        self::assertTrue($result['isReady']);
        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['issues']);
        self::assertSame('unverifiable', $result['correlation_status']);
    }

    public function testObjectivelyInvalidServiceCodeBlocksEmission(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '9999',
            'codigo_tributacao_nacional' => '010701',
        ]);

        self::assertFalse($result['isReady']);
        self::assertSame('invalid', $result['status']);
        self::assertContains('invalid_service_code', $result['issues']);
    }

    public function testObjectivelyInvalidNationalCodeBlocksEmission(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '999999',
        ]);

        self::assertFalse($result['isReady']);
        self::assertContains('invalid_national_code', $result['issues']);
    }

    public function testMissingNationalCodeBlocksEmissionBeforeGatewaySubmission(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '',
        ]);

        self::assertFalse($result['isReady']);
        self::assertSame('warning', $result['status']);
        self::assertContains('missing_national_code', $result['issues']);
    }

    public function testExplicitUnsupportedRtcSupplyCategoryBlocksOrdinaryIssuance(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'rtc_supply_category' => 'digital_platform',
        ]);

        self::assertFalse($result['isReady']);
        self::assertSame('unverifiable', $result['status']);
        self::assertContains('unsupported_rtc_supply_category', $result['issues']);
    }

    public function testExplicitOrdinaryLc116CategoryPreservesExistingEmissionRules(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'rtc_supply_category' => 'ordinary_lc116',
        ]);

        self::assertTrue($result['isReady']);
        self::assertSame('valid', $result['status']);
    }

    public function testMissingProfileBlocksEmissionBecauseNationalCodeCannotBeProduced(): void
    {
        $result = (new FiscalProfileEmissionReadiness())->evaluate([]);

        self::assertFalse($result['isReady']);
        self::assertSame('unverifiable', $result['status']);
        self::assertContains('missing_profile', $result['issues']);
    }
}
