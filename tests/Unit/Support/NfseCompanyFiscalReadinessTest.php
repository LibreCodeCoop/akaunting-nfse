<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NfseCompanyFiscalReadiness;
use PHPUnit\Framework\TestCase;

final class NfseCompanyFiscalReadinessTest extends TestCase
{
    public function testIncompleteProfileDoesNotDependOnVaultAvailability(): void
    {
        $model = [
            'status' => 'active',
            'certificate_valid_from' => 50,
            'certificate_valid_to' => 150,
            'fiscal_parameters' => ['municipio_ibge' => '3304557'],
        ];
        $readiness = (new NfseCompanyFiscalReadiness())->evaluate($model, 100);

        self::assertFalse($readiness['basic_complete']);
        self::assertContains('uf', $readiness['pending']);
        self::assertContains('opcao_simples_nacional', $readiness['pending']);
        self::assertNotContains('vault', $readiness['pending']);
    }

    public function testBasicFiscalFieldsDoNotMeanMunicipalAuthorization(): void
    {
        $model = [
            'status' => 'active',
            'certificate_valid_from' => 50,
            'certificate_valid_to' => 150,
            'fiscal_parameters' => [
                'uf' => 'RJ', 'municipio_ibge' => '3304557',
                'opcao_simples_nacional' => '3',
                'regime_apuracao_simples_nacional' => '2',
                'regime_especial_tributacao' => '9',
                'tributacao_issqn' => '1', 'tipo_retencao_iss' => '1',
            ],
        ];

        $readiness = (new NfseCompanyFiscalReadiness())->evaluate($model, 100);
        self::assertTrue($readiness['basic_complete']);
        self::assertTrue($readiness['municipal_review_required']);
    }

    public function testExpiredCertificateAndInvalidSimplesCombinationRemainPending(): void
    {
        $model = [
            'status' => 'active',
            'certificate_valid_from' => 50,
            'certificate_valid_to' => 90,
            'fiscal_parameters' => [
                'uf' => 'RJ', 'municipio_ibge' => '3304557',
                'opcao_simples_nacional' => '3',
                'regime_apuracao_simples_nacional' => '1',
                'regime_especial_tributacao' => '9',
                'tributacao_issqn' => '1', 'tipo_retencao_iss' => '1',
            ],
        ];
        $readiness = (new NfseCompanyFiscalReadiness())->evaluate($model, 100);
        self::assertFalse($readiness['basic_complete']);
        self::assertContains('certificate_validity', $readiness['pending']);
        self::assertContains('regime_especial_tributacao', $readiness['pending']);
    }
}
