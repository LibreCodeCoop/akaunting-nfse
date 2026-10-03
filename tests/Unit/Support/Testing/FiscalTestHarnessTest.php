<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support\Testing;

use Modules\Nfse\Support\Testing\DeterministicFiscalHttpTransport;
use Modules\Nfse\Support\Testing\EnvironmentFixtureSecretStore;
use Modules\Nfse\Support\Testing\FiscalTestHarnessConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
use Modules\Nfse\Tests\TestCase;

final class FiscalTestHarnessTest extends TestCase
{
    public function testHarnessIsDisabledWhenFlagIsFalse(): void
    {
        self::assertFalse(FiscalTestHarnessConfig::enabled('production', false));
        self::assertFalse(FiscalTestHarnessConfig::enabled('testing', '0'));
    }

    public function testHarnessCanOnlyBeEnabledInLocalOrTesting(): void
    {
        self::assertTrue(FiscalTestHarnessConfig::enabled('testing', '1'));
        self::assertTrue(FiscalTestHarnessConfig::enabled('local', 'true'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('local or testing');

        FiscalTestHarnessConfig::enabled('production', '1');
    }

    public function testFixtureSecretStoreReturnsOnlyConfiguredCertificateSecret(): void
    {
        $store = new EnvironmentFixtureSecretStore(
            cnpj: '11222333000181',
            password: 'secret',
            pfxPath: '/tmp/test.p12',
        );

        self::assertSame([
            'password' => 'secret',
            'pfx_path' => '/tmp/test.p12',
        ], $store->get('pfx/11222333000181'));

        $this->expectException(SecretStoreException::class);
        $store->get('pfx/00000000000000');
    }

    public function testFixtureSecretStoreIsReadOnly(): void
    {
        $store = new EnvironmentFixtureSecretStore(
            cnpj: '11222333000181',
            password: 'secret',
            pfxPath: '/tmp/test.p12',
        );

        $this->expectException(SecretStoreException::class);
        $store->put('pfx/11222333000181', ['password' => 'changed']);
    }

    public function testDeterministicTransportEmitsWithoutNetwork(): void
    {
        $transport = new DeterministicFiscalHttpTransport();

        $response = $transport->request(new HttpRequestData(
            method: 'POST',
            url: 'https://fixture.invalid/SefinNacional/nfse',
            body: '{}',
        ));

        self::assertSame(201, $response->status);

        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('42', $payload['nNFSe'] ?? null);
        self::assertSame('TEST-ACCESS-KEY-42', $payload['chaveAcesso'] ?? null);
        self::assertNotEmpty($payload['nfseXmlGZipB64'] ?? null);
    }

    public function testDeterministicTransportSupportsReadOnlyAdnAndMunicipalQueries(): void
    {
        $transport = new DeterministicFiscalHttpTransport();

        $adn = $transport->request(new HttpRequestData(
            method: 'GET',
            url: 'https://fixture.invalid/ADN/DFe/0?lote=true',
        ));
        $municipal = $transport->request(new HttpRequestData(
            method: 'GET',
            url: 'https://fixture.invalid/parametros/3303302/convenio',
        ));

        self::assertSame(200, $adn->status);
        self::assertStringContainsString('PROCESSADO', $adn->body);
        self::assertSame(200, $municipal->status);
        self::assertStringContainsString('convenio', $municipal->body);
    }

    public function testDeterministicTransportRejectsUnexpectedProtocolCalls(): void
    {
        $transport = new DeterministicFiscalHttpTransport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected deterministic fiscal request');

        $transport->request(new HttpRequestData(
            method: 'POST',
            url: 'https://fixture.invalid/unknown',
        ));
    }
}
