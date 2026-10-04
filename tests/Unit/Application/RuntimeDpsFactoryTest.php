<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\RuntimeDpsFactory;
use PHPUnit\Framework\TestCase;

final class RuntimeDpsFactoryTest extends TestCase
{
    public function testBuildsDpsFromSupportedRuntimeFields(): void
    {
        $dps = (new RuntimeDpsFactory())->make([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'itemListaServico' => '0107',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Teste',
            'fieldFromFutureRuntime' => 'ignored',
        ]);

        self::assertSame('11222333000181', $dps->cnpjPrestador);
        self::assertSame('3303302', $dps->municipioIbge);
        self::assertSame('0107', $dps->itemListaServico);
    }

    public function testIgnoresUnknownOptionalFieldsForRuntimeCompatibility(): void
    {
        $dps = (new RuntimeDpsFactory())->make([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'itemListaServico' => '0107',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Teste',
            '__unsupported_optional_field__' => 'ignored',
        ]);

        self::assertSame('100.00', $dps->valorServico);
    }

    public function testFailsClosedWhenScenarioRequiresUnsupportedRuntimeField(): void
    {
        $factory = new RuntimeDpsFactory();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Installed nfse-php runtime does not support required DPS field: __unsupported_required_field__',
        );

        $factory->make([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'itemListaServico' => '0107',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Teste',
        ], ['__unsupported_required_field__']);
    }

    public function testAcceptsRequiredFieldWhenInstalledRuntimeSupportsIt(): void
    {
        $dps = (new RuntimeDpsFactory())->make([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'itemListaServico' => '0107',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Teste',
            'codigoTributacaoNacional' => '010701',
        ], ['codigoTributacaoNacional']);

        self::assertSame('010701', $dps->codigoTributacaoNacional);
    }
}
