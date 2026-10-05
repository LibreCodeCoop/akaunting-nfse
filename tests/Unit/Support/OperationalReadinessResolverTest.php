<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\OperationalReadinessResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use PHPUnit\Framework\TestCase;

final class OperationalReadinessResolverTest extends TestCase
{
    public function testResolvesLocalCertificateAndSecretFactsOnceForReadinessModel(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'nfse-readiness-');
        self::assertIsString($certificate);

        try {
            $result = (new OperationalReadinessResolver())->evaluate(
                settings: [
                    'cnpj_prestador' => '11222333000181',
                    'municipio_ibge' => '3303302',
                    'bao_addr' => 'https://vault.example.test',
                    'bao_mount' => 'nfse',
                ],
                serviceCode: '0107',
                secretStore: $this->secretStore([
                    'password' => 'fixture-password',
                    'pfx_path' => '/fixture/certificate.pfx',
                ]),
                certificatePath: $certificate,
            );

            self::assertTrue($result['isReady']);
            self::assertTrue($result['checklist']['certificate']);
            self::assertTrue($result['checklist']['certificate_secret']);
        } finally {
            @unlink($certificate);
        }
    }

    public function testUnavailableSecretStoreBecomesReadinessBlockerWithoutThrowing(): void
    {
        $store = new class () implements SecretStoreInterface {
            public function get(string $path): array
            {
                throw new \RuntimeException('Vault unavailable');
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };

        $result = (new OperationalReadinessResolver())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => 'nfse',
            ],
            serviceCode: '0107',
            secretStore: $store,
            certificatePath: __FILE__,
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['checklist']['certificate_secret']);
    }

    /**
     * @param array<string,string> $secret
     */
    private function secretStore(array $secret): SecretStoreInterface
    {
        return new class ($secret) implements SecretStoreInterface {
            /** @param array<string,string> $secret */
            public function __construct(private readonly array $secret)
            {
            }

            public function get(string $path): array
            {
                return $this->secret;
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };
    }
}
