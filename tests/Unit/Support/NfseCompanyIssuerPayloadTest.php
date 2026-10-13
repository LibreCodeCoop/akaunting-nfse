<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NfseCompanyIssuerPayload;
use PHPUnit\Framework\TestCase;

final class NfseCompanyIssuerPayloadTest extends TestCase
{
    public function testContactsComeFromTheProvidedIssuerNotOtherProfiles(): void
    {
        $mapper = new NfseCompanyIssuerPayload();
        $fallback = (object) ['phone' => '111111111', 'email' => 'native@example.test'];

        self::assertSame([
            'telefone' => '21988887777',
            'email' => 'issuer@example.test',
        ], $mapper->contact([
            'prestador_telefone' => '(21) 98888-7777',
            'prestador_email' => 'issuer@example.test',
        ], $fallback));
        self::assertSame([
            'telefone' => '111111111',
            'email' => 'native@example.test',
        ], $mapper->contact([], $fallback));
        self::assertSame(['telefone' => '', 'email' => ''], $mapper->contact([]));
    }

    public function testEnvironmentDefaultsToRestricted(): void
    {
        $mapper = new NfseCompanyIssuerPayload();
        self::assertTrue($mapper->sandboxMode([]));
        self::assertTrue($mapper->sandboxMode(['sandbox_mode' => 'invalid']));
        self::assertFalse($mapper->sandboxMode(['sandbox_mode' => '0']));
        self::assertFalse($mapper->sandboxMode(['sandbox_mode' => false]));
        self::assertTrue($mapper->sandboxMode(['sandbox_mode' => '1']));
    }

    public function testDifferentIssuersHaveDifferentIssqnAndIbsCbs(): void
    {
        $mapper = new NfseCompanyIssuerPayload();
        $a = [
            'tributacao_issqn' => '3', 'issqn_pais_resultado' => 'US',
            'tipo_retencao_iss' => '1',
            'ibs_cbs_enabled' => '1', 'ibs_cbs_ind_final' => '1',
            'ibs_cbs_ind_dest' => '0', 'ibs_cbs_c_ind_op' => '123456',
            'ibs_cbs_cst' => '123', 'ibs_cbs_c_class_trib' => '654321',
        ];
        $b = [
            'tributacao_issqn' => '2', 'issqn_tipo_imunidade' => '3',
            'tipo_retencao_iss' => '2',
        ];
        self::assertSame('US', $mapper->issqn($a)['issqnPaisResultado']);
        self::assertSame('', $mapper->issqn($b)['issqnPaisResultado']);
        self::assertSame(3, $mapper->issqn($b)['issqnTipoImunidade']);
        self::assertSame(2, $mapper->issqn($b)['tipoRetencaoIss']);
        self::assertTrue($mapper->ibsCbs($a)['enabled']);
        self::assertSame(1, $mapper->ibsCbs($a)['ibsCbsIndFinal']);
        self::assertSame(0, $mapper->ibsCbs($a)['ibsCbsIndDest']);
        self::assertSame('123456', $mapper->ibsCbs($a)['ibsCbsCodigoIndicadorOperacao']);
        self::assertFalse($mapper->ibsCbs($b)['enabled']);
    }
}
