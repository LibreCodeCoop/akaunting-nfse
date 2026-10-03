<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Tests\Support\FakeHttpTransport;
use Modules\Nfse\Tests\Support\InMemorySecretStore;
use Modules\Nfse\Tests\Support\SyntheticPkcs12;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;
use Tests\Feature\FeatureTestCase;

final class FiscalGatewayHarnessTest extends FeatureTestCase
{
    private const CNPJ = '11222333000181';

    private string $pfxDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs();

        $this->pfxDirectory = storage_path('app/nfse/pfx');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pfxDirectory . '/synthetic-nfse-test.p12') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testContainerCanRunFiscalClientWithSyntheticCertificateAndFakeTransport(): void
    {
        $pkcs12 = SyntheticPkcs12::create($this->pfxDirectory);

        setting()->set([
            'nfse.cnpj_prestador' => self::CNPJ,
            'nfse.sandbox_mode' => '1',
        ]);
        setting()->save();

        $secretStore = new InMemorySecretStore([
            'pfx/' . self::CNPJ => [
                'password' => $pkcs12['password'],
                'pfx_path' => $pkcs12['path'],
            ],
        ]);

        $transport = new FakeHttpTransport(new HttpResponseData(
            status: 200,
            body: json_encode([
                'StatusProcessamento' => 'PROCESSADO',
                'TipoAmbiente' => '2',
                'DataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'LoteDFe' => [],
            ], JSON_THROW_ON_ERROR),
        ));

        $this->app->instance(SecretStoreInterface::class, $secretStore);
        $this->app->instance(HttpTransportInterface::class, $transport);

        $context = $this->app->make(FiscalClientFactory::class)->adn(true);

        try {
            $result = $context->adnClient()->getDfe(0);

            self::assertSame('PROCESSADO', $result->statusProcessamento);
            self::assertCount(1, $transport->requests);

            $request = $transport->requests[0];

            self::assertNotNull($request->clientCertificatePath);
            self::assertNotNull($request->clientPrivateKeyPath);
            self::assertFileExists((string) $request->clientCertificatePath);
            self::assertFileExists((string) $request->clientPrivateKeyPath);
            self::assertSame('GET', $request->method);
            self::assertStringContainsString('/DFe/0?lote=true', $request->url);
        } finally {
            $certificatePath = $transport->requests[0]->clientCertificatePath ?? null;
            $privateKeyPath = $transport->requests[0]->clientPrivateKeyPath ?? null;

            $context->close();

            if ($certificatePath !== null) {
                self::assertFileDoesNotExist($certificatePath);
            }

            if ($privateKeyPath !== null) {
                self::assertFileDoesNotExist($privateKeyPath);
            }
        }

        self::assertFileExists($pkcs12['path']);
    }
}
