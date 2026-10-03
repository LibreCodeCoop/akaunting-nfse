<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NfseRuntimeContextFactory;
use Modules\Nfse\Support\TransportCertificateManager;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

final class NfseRuntimeContextFactoryTest extends TestCase
{
    private string $storageRoot;
    private string $pfxPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/nfse-runtime-context-' . uniqid('', true);
        mkdir($this->storageRoot, 0o777, true);

        $this->pfxPath = $this->storageRoot . '/source.pfx';
        file_put_contents($this->pfxPath, 'synthetic-pfx');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            foreach (glob($this->storageRoot . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    public function testCreatesReusableTransportContextAndCleansArtifacts(): void
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
            temporaryDirectoryResolver: fn (): string => $this->storageRoot,
            pfxImporter: static fn (): array => [
                "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
                "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n",
            ],
            pemValidator: static function (): void {
            },
        );

        $factory = new NfseRuntimeContextFactory($manager);
        $context = $factory->create(
            new CertConfig(
                cnpj: '12345678000195',
                pfxPath: $this->pfxPath,
                vaultPath: 'pfx/12345678000195',
            ),
            $secretStore,
        );

        self::assertSame($secretStore, $context->secretStore);
        self::assertSame('12345678000195', $context->cert->cnpj);
        self::assertSame($this->pfxPath, $context->cert->pfxPath);
        self::assertSame('pfx/12345678000195', $context->cert->vaultPath);
        self::assertNotNull($context->cert->transportCertificatePath);
        self::assertNotNull($context->cert->transportPrivateKeyPath);
        self::assertFileExists((string) $context->cert->transportCertificatePath);
        self::assertFileExists((string) $context->cert->transportPrivateKeyPath);

        ($context->cleanup)();

        self::assertFileDoesNotExist((string) $context->cert->transportCertificatePath);
        self::assertFileDoesNotExist((string) $context->cert->transportPrivateKeyPath);
    }
}
