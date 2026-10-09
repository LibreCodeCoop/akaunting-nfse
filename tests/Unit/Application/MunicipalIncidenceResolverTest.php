<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\MunicipalIncidenceResolver;
use PHPUnit\Framework\TestCase;

final class MunicipalIncidenceResolverTest extends TestCase
{
    public function testOrdinaryServiceRequiresConfirmedEstablishment(): void
    {
        $facts = [
            'item_lista_servico' => '1.07',
            'provider_establishment_municipality' => '3303302',
        ];
        $resolver = new MunicipalIncidenceResolver();

        self::assertNull($resolver->resolve($facts)['municipio_ibge']);

        $resolved = $resolver->resolve($facts + ['provider_establishment_confirmed' => true]);
        self::assertSame('3303302', $resolved['municipio_ibge']);
        self::assertSame('lc116_art3_default', $resolved['source']);
    }

    public function testExceptionsAndSpecialCasesNeverDefaultToProvider(): void
    {
        $resolver = new MunicipalIncidenceResolver();
        foreach (['3.05', '7.02', '7.19', '14.14', '12.01', '16.02',
            '17.05', '20.01', '4.22', '5.09', '15.01', '22.01', '3.04'] as $service) {
            $result = $resolver->resolve([
                'item_lista_servico' => $service,
                'provider_establishment_municipality' => '3303302',
                'provider_establishment_confirmed' => true,
            ]);
            self::assertNull($result['municipio_ibge'], $service);
            self::assertSame('lc116_location_exception_requires_review', $result['reason'], $service);
        }

        self::assertSame('3303302', $resolver->resolve([
            'item_lista_servico' => '12.13',
            'provider_establishment_municipality' => '3303302',
            'provider_establishment_confirmed' => true,
        ])['municipio_ibge']);

        self::assertNull($resolver->resolve([
            'item_lista_servico' => '0107',
            'provider_establishment_municipality' => '3303302',
            'provider_establishment_confirmed' => true,
            'service_from_abroad' => true,
        ])['municipio_ibge']);
    }

    public function testExplicitIncidenceNeedsDocumentedFiscalBasis(): void
    {
        $resolver = new MunicipalIncidenceResolver();
        $facts = ['item_lista_servico' => '0702', 'incidence_municipality' => '3304557'];
        self::assertSame('incidence_basis_not_established', $resolver->resolve($facts)['reason']);

        $result = $resolver->resolve($facts + ['incidence_basis' => 'documented_fiscal_context']);
        self::assertSame('3304557', $result['municipio_ibge']);
        self::assertSame('documented_fiscal_context', $result['source']);
        self::assertNull($resolver->resolve([
            'item_lista_servico' => '0702',
            'incidence_municipality' => '0',
            'incidence_basis' => 'documented_fiscal_context',
        ])['municipio_ibge']);
    }

    public function testUnknownServiceAndSpecialTaxationStayUnverifiable(): void
    {
        $resolver = new MunicipalIncidenceResolver();
        self::assertSame('lc116_service_unavailable', $resolver->resolve([
            'provider_establishment_confirmed' => true,
            'provider_establishment_municipality' => '3303302',
        ])['reason']);
        self::assertSame('special_iss_taxation_requires_review', $resolver->resolve([
            'item_lista_servico' => '0107',
            'provider_establishment_confirmed' => true,
            'provider_establishment_municipality' => '3303302',
            'tributacao_issqn' => 3,
        ])['reason']);
    }
}
