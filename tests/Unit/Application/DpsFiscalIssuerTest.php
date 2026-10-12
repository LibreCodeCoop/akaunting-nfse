<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\DpsFiscalIssuer;
use PHPUnit\Framework\TestCase;

final class DpsFiscalIssuerTest extends TestCase
{
    public function testCpfAndAlphanumericCnpjStayDistinctAndNormalized(): void
    {
        $cpf = new DpsFiscalIssuer('3303302', 1, '11122233344');
        self::assertSame('00011122233344', $cpf->registration);

        $cnpj = new DpsFiscalIssuer('3303302', 2, '12abc34501de35');
        self::assertSame('12ABC34501DE35', $cnpj->registration);
        self::assertSame(2, $cnpj->registrationType);
    }

    public function testInvalidMunicipalityAndRegistrationFailClosed(): void
    {
        foreach ([
            ['03303302', 2, '11222333000181'],
            ['3303302', 1, '11222333000181'],
            ['3303302', 2, '11122233344'],
            ['3303302', 3, '11222333000181'],
            ['3303302', 2, 'invalid-cnpj'],
        ] as [$municipality, $type, $registration]) {
            try {
                new DpsFiscalIssuer($municipality, $type, $registration);
                self::fail('Invalid issuer identity was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', trim($exception->getMessage()));
            }
        }
    }
}
