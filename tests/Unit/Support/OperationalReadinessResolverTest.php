<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\OperationalReadinessResolver;
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
                    'bao_token' => 'test-token',
                ],
                hasCertificateSecret: true,
                certificatePath: $certificate,
            );

            self::assertTrue($result['isReady']);
            self::assertTrue($result['checklist']['certificate']);
            self::assertTrue($result['checklist']['certificate_secret']);
            self::assertArrayNotHasKey('item_lista_servico', $result['checklist']);
        } finally {
            @unlink($certificate);
        }
    }

    public function testUnavailableFiscalRuntimeBlocksOperationalReadiness(): void
    {
        $result = (new OperationalReadinessResolver())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => 'nfse',
                'bao_token' => 'test-token',
            ],
            hasCertificateSecret: true,
            certificatePath: __FILE__,
            runtimeContractAvailable: false,
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['checklist']['runtime_contract']);
    }

    public function testMissingCertificateSecretRemainsReadinessBlocker(): void
    {
        $result = (new OperationalReadinessResolver())->evaluate(
            settings: [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3303302',
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => 'nfse',
                'bao_token' => 'test-token',
            ],
            hasCertificateSecret: false,
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
