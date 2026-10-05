<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IbsCbsPayloadResolver;
use PHPUnit\Framework\TestCase;

final class IbsCbsPayloadResolverTest extends TestCase
{
    public function testDisabledPayloadDoesNotLeakConfiguredValues(): void
    {
        $payload = (new IbsCbsPayloadResolver())->resolve([
            'enabled' => false,
            'c_ind_op' => '100001',
            'cst' => '000',
        ]);

        self::assertFalse($payload['enabled']);
        self::assertSame('', $payload['ibsCbsCodigoIndicadorOperacao']);
        self::assertSame('', $payload['ibsCbsCst']);
        self::assertNull($payload['ibsCbsIndFinal']);
    }

    public function testEnabledPayloadNormalizesIndicatorsAndStrings(): void
    {
        $payload = (new IbsCbsPayloadResolver())->resolve([
            'enabled' => 'on',
            'ind_final' => '1',
            'ind_dest' => '0',
            'c_ind_op' => ' 100001 ',
            'cst' => ' 000 ',
            'c_class_trib' => ' 000001 ',
        ]);

        self::assertTrue($payload['enabled']);
        self::assertSame(0, $payload['ibsCbsFinalidade']);
        self::assertSame(1, $payload['ibsCbsIndFinal']);
        self::assertSame(0, $payload['ibsCbsIndDest']);
        self::assertSame('100001', $payload['ibsCbsCodigoIndicadorOperacao']);
        self::assertSame('000', $payload['ibsCbsCst']);
        self::assertSame('000001', $payload['ibsCbsClassificacaoTributaria']);
    }

    public function testInvalidIndicatorsRemainExplicitlyUnresolved(): void
    {
        $payload = (new IbsCbsPayloadResolver())->resolve([
            'enabled' => 1,
            'ind_final' => '9',
            'ind_dest' => '9',
        ]);

        self::assertNull($payload['ibsCbsIndFinal']);
        self::assertNull($payload['ibsCbsIndDest']);
    }
}
