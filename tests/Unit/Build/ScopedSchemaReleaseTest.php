<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\DpsSchemaValidator;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder;

final class ScopedSchemaReleaseTest extends TestCase
{
    public function testScopedRuntimeContainsBothOfficialSchemaReleases(): void
    {
        $module = dirname(__DIR__, 3);
        require_once $module . '/3rdparty/scoped/autoload.php';

        self::assertSame(
            '20261020',
            (new EnvironmentConfig(sandboxMode: true))->dpsSchemaRelease,
        );
        self::assertSame(
            '20260209',
            (new EnvironmentConfig(sandboxMode: false))->dpsSchemaRelease,
        );

        foreach (['1.01', '1.01-20261020'] as $release) {
            self::assertFileExists(
                $module . '/3rdparty/scoped/librecodeoop/nfse-php/references/schemas/nfse/'
                . $release . '/DPS_v1.01.xsd',
            );
        }

        $dps = new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0101',
            valorServico: '1500.00',
            aliquota: '2.00',
            discriminacao: 'Teste sintetico',
            codigoTributacaoMunicipal: '001',
            // Synthetic disclosure values for isolated XML contract tests.
            totalTributosPercentualFederal: '0.00',
            totalTributosPercentualEstadual: '0.00',
            totalTributosPercentualMunicipal: '0.00',
        );

        $restricted = (new XmlBuilder())->buildDps(
            $dps,
            EnvironmentConfig::SCHEMA_RELEASE_RESTRICTED_20261020,
        );
        self::assertSame([], (new DpsSchemaValidator(
            schemaRelease: EnvironmentConfig::SCHEMA_RELEASE_RESTRICTED_20261020,
        ))->validate($restricted));
        self::assertStringContainsString('<finNFSe>0</finNFSe>', $restricted);
    }
}
