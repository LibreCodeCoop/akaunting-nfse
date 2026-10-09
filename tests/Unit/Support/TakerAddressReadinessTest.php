<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\TakerAddressReadiness;
use PHPUnit\Framework\TestCase;

final class TakerAddressReadinessTest extends TestCase
{
    public function testBlocksMissingDomesticTakerAddressForRemoteServiceIndicator(): void
    {
        $missing = (new TakerAddressReadiness())->missing([
            'codigo_municipio' => '',
            'cep' => '',
        ], ['enabled' => true, 'ibsCbsCodigoIndicadorOperacao' => '100301']);
        self::assertSame(['município IBGE', 'CEP', 'logradouro', 'número', 'bairro'], $missing);
    }

    public function testCompleteDomesticAddressPasses(): void
    {
        self::assertSame([], (new TakerAddressReadiness())->missing([
            'codigo_municipio' => '3550308',
            'cep' => '04578000',
            'logradouro' => 'Av. das Nações Unidas',
            'numero' => '11541',
            'bairro' => 'Brooklin Novo',
        ], ['enabled' => true, 'ibsCbsCodigoIndicadorOperacao' => '100301']));
    }

    public function testUnrelatedOrDisabledIndicatorIsNotBlocked(): void
    {
        self::assertSame([], (new TakerAddressReadiness())->missing([], [
            'enabled' => false, 'ibsCbsCodigoIndicadorOperacao' => '100301',
        ]));
        self::assertSame([], (new TakerAddressReadiness())->missing([], [
            'enabled' => true, 'ibsCbsCodigoIndicadorOperacao' => '020101',
        ]));
    }
}
