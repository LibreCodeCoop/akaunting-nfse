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

    public function testIssqnSpecificMissingMetadataIsReportedWithoutInventingDefaults(): void
    {
        $base = [
            'status' => 'active',
            'certificate_valid_from' => 50,
            'certificate_valid_to' => 150,
            'fiscal_parameters' => [
                'uf' => 'RJ', 'municipio_ibge' => '3304557',
                'opcao_simples_nacional' => '1', 'regime_especial_tributacao' => '0',
                'tipo_retencao_iss' => '1',
            ],
        ];
        $review = new NfseCompanyFiscalReadiness();

        $export = $base;
        $export['fiscal_parameters']['tributacao_issqn'] = '3';
        self::assertContains('issqn_pais_resultado', $review->evaluate($export, 100)['pending']);
        $export['fiscal_parameters']['issqn_pais_resultado'] = 'US';
        self::assertNotContains('issqn_pais_resultado', $review->evaluate($export, 100)['pending']);

        $immunity = $base;
        $immunity['fiscal_parameters']['tributacao_issqn'] = '2';
        self::assertContains('issqn_tipo_imunidade', $review->evaluate($immunity, 100)['pending']);
        $immunity['fiscal_parameters']['issqn_tipo_imunidade'] = '2';
        self::assertNotContains('issqn_tipo_imunidade', $review->evaluate($immunity, 100)['pending']);

        $suspension = $base;
        $suspension['fiscal_parameters']['tributacao_issqn'] = '1';
        $suspension['fiscal_parameters']['issqn_tipo_suspensao'] = '1';
        self::assertContains('issqn_numero_processo_suspensao', $review->evaluate($suspension, 100)['pending']);
        $suspension['fiscal_parameters']['issqn_numero_processo_suspensao'] = str_repeat('1', 30);
        self::assertNotContains('issqn_numero_processo_suspensao', $review->evaluate($suspension, 100)['pending']);
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
