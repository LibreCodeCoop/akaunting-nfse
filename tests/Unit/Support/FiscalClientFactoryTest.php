<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\NfseRuntimeContextFactory;
use Modules\Nfse\Support\TransportCertificateManager;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;

final class FiscalClientFactoryTest extends TestCase
{
    private string $temporaryDirectory;
    private string $pfxPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . '/nfse-fiscal-client-' . uniqid('', true);
        mkdir($this->temporaryDirectory, 0o777, true);

        $this->pfxPath = $this->temporaryDirectory . '/source.pfx';
        file_put_contents($this->pfxPath, 'synthetic-pfx');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testInjectedTransportIsSharedBySefinAdnAndMunicipalClients(): void
    {
        $transport = new class () implements HttpTransportInterface {
            /** @var list<HttpRequestData> */
            public array $requests = [];

            public function request(HttpRequestData $request): HttpResponseData
            {
                $this->requests[] = $request;

                if (str_contains($request->url, '/SefinNacional/nfse/ACCESS-KEY')) {
                    return new HttpResponseData(
                        status: 200,
                        body: '{"nNFSe":"42","chaveAcesso":"ACCESS-KEY","dataHoraProcessamento":"2026-10-03T12:00:00-03:00"}',
                    );
                }

                if (str_contains($request->url, '/NFSe/ACCESS-KEY/Eventos')) {
                    return new HttpResponseData(
                        status: 200,
                        body: '{"StatusProcessamento":"OK","LoteDFe":[],"TipoAmbiente":"2","DataHoraProcessamento":"2026-10-03T12:00:00-03:00"}',
                    );
                }

                return new HttpResponseData(
                    status: 200,
                    body: '{"convenio":true}',
                );
            }
        };

        $factory = $this->factory($transport);

        $nfse = $factory->nfse(true);
        self::assertSame('42', $nfse->nfseClient()->query('ACCESS-KEY')->nfseNumber);
        $this->assertTransportMaterialLifecycle($nfse, $transport->requests[array_key_last($transport->requests)]);

        $adn = $factory->adn(true);
        self::assertSame('OK', $adn->adnClient()->listEvents('ACCESS-KEY')->statusProcessamento);
        $this->assertTransportMaterialLifecycle($adn, $transport->requests[array_key_last($transport->requests)]);

        $municipal = $factory->municipalParameters(true);
        self::assertSame(
            ['convenio' => true],
            $municipal->municipalParametersClient()->convenio('3303302'),
        );
        $this->assertTransportMaterialLifecycle($municipal, $transport->requests[array_key_last($transport->requests)]);

        self::assertCount(3, $transport->requests);
    }

    public function testContextCleanupIsIdempotent(): void
    {
        $transport = new class () implements HttpTransportInterface {
            public function request(HttpRequestData $request): HttpResponseData
            {
                return new HttpResponseData(status: 200, body: '{}');
            }
        };

        $context = $this->factory($transport)->adn(true);
        $client = $context->adnClient();

        $readCert = \Closure::bind(
            static fn ($client) => $client->cert,
            null,
            $client::class,
        );
        $cert = $readCert($client);

        self::assertFileExists((string) $cert->transportCertificatePath);
        self::assertFileExists((string) $cert->transportPrivateKeyPath);

        $context->close();
        $context->close();

        self::assertFileDoesNotExist((string) $cert->transportCertificatePath);
        self::assertFileDoesNotExist((string) $cert->transportPrivateKeyPath);
    }

    private function factory(HttpTransportInterface $transport): FiscalClientFactory
    {
        $secretStore = new class ($this->pfxPath) implements SecretStoreInterface {
            public function __construct(private readonly string $pfxPath)
            {
            }

            public function get(string $path): array
            {
                return [
                    'password' => 'test-password',
                    'pfx_path' => $this->pfxPath,
                ];
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };

        $manager = new TransportCertificateManager(
            temporaryDirectoryResolver: fn (): string => $this->temporaryDirectory,
            pfxImporter: static fn (): array => [
                "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
                "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n",
            ],
            pemValidator: static function (): void {
            },
        );

        return new FiscalClientFactory(
            transport: $transport,
            runtimeContextFactory: new NfseRuntimeContextFactory($manager),
            secretStoreFactory: static fn (): SecretStoreInterface => $secretStore,
            cnpjResolver: static fn (): string => '12345678000195',
            pfxPathResolver: fn (): string => $this->pfxPath,
        );
    }

    private function assertTransportMaterialLifecycle(
        \Modules\Nfse\Support\FiscalClientContext $context,
        HttpRequestData $request,
    ): void {
        self::assertNotNull($request->clientCertificatePath);
        self::assertNotNull($request->clientPrivateKeyPath);
        self::assertFileExists((string) $request->clientCertificatePath);
        self::assertFileExists((string) $request->clientPrivateKeyPath);

        $context->close();

        self::assertFileDoesNotExist((string) $request->clientCertificatePath);
        self::assertFileDoesNotExist((string) $request->clientPrivateKeyPath);
    }
}
