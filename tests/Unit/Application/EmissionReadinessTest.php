<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\EmissionReadiness;
use PHPUnit\Framework\TestCase;

final class EmissionReadinessTest extends TestCase
{
    public function testReadyCommonPath(): void
    {
        $result = (new EmissionReadiness())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'http://openbao:8200',
                'bao_mount' => 'nfse',
                'certificate_valid_from' => 100,
                'certificate_valid_to' => 300,
            ],
            hasLocalCertificate: true,
            hasCertificateSecret: true,
            serviceCode: '0107',
            now: 200,
        );

        self::assertTrue($result['isReady']);
        self::assertFalse(in_array(false, $result['checklist'], true));
    }

    public function testReportsAllIndependentBlockers(): void
    {
        $result = (new EmissionReadiness())->evaluate(
            settings: [],
            hasLocalCertificate: false,
            hasCertificateSecret: false,
            serviceCode: '',
        );

        self::assertFalse($result['isReady']);
        self::assertSame([
            'cnpj_prestador' => false,
            'municipio_ibge' => false,
            'item_lista_servico' => false,
            'bao_addr' => false,
            'bao_mount' => false,
            'certificate' => false,
            'certificate_secret' => false,
        ], $result['checklist']);
    }

    public function testMissingVaultEndpointBlocksReadinessEvenWhenCertificateFactsArePresent(): void
    {
        $result = (new EmissionReadiness())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => '',
                'bao_mount' => '',
            ],
            hasLocalCertificate: true,
            hasCertificateSecret: true,
            serviceCode: '0107',
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['checklist']['bao_addr']);
        self::assertFalse($result['checklist']['bao_mount']);
    }

    public function testExpiredCertificateBlocksIssuance(): void
    {
        $result = (new EmissionReadiness())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'http://openbao:8200',
                'bao_mount' => 'nfse',
                'certificate_valid_from' => 100,
                'certificate_valid_to' => 200,
            ],
            hasLocalCertificate: true,
            hasCertificateSecret: true,
            serviceCode: '0107',
            now: 201,
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['checklist']['certificate_valid']);
    }

    public function testEnabledIbsCbsRequiresCompleteCoreClassification(): void
    {
        $result = (new EmissionReadiness())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'http://openbao:8200',
                'bao_mount' => 'nfse',
                'ibs_cbs_enabled' => '1',
                'ibs_cbs_ind_dest' => '',
                'ibs_cbs_c_ind_op' => '',
                'ibs_cbs_cst' => '',
                'ibs_cbs_c_class_trib' => '',
            ],
            hasLocalCertificate: true,
            hasCertificateSecret: true,
            serviceCode: '0107',
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['checklist']['ibs_cbs']);
    }
}
