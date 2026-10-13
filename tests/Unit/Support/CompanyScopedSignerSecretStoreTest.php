<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\CompanyScopedSignerSecretStore;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class CompanyScopedSignerSecretStoreTest extends TestCase
{
    private const CNPJ = '11222333000181';

    public function testTwoCompaniesWithSameCnpjReadDifferentCertificateRevisions(): void
    {
        $ownerA = 'pfx/companies/11/' . str_repeat('a', 32);
        $ownerB = 'pfx/companies/12/' . str_repeat('b', 32);

        $store = new class ($ownerA, $ownerB) implements SecretStoreInterface {
            /** @var list<string> */
            public array $requests = [];

            public function __construct(private string $first, private string $second)
            {
            }

            public function get(string $path): array
            {
                $this->requests[] = $path;

                return match ($path) {
                    $this->first => ['password' => 'secret-a', 'pfx_path' => '/synthetic/company-a.pfx'],
                    $this->second => ['password' => 'secret-b', 'pfx_path' => '/synthetic/company-b.pfx'],
                    default => throw new SecretStoreException('Unknown tenant certificate revision.'),
                };
            }

            public function put(string $path, array $data): void
            {
                throw new \LogicException('Signer should never persist a certificate.');
            }

            public function delete(string $path): void
            {
                throw new \LogicException('Signer should never delete a certificate.');
            }
        };

        $first = new CompanyScopedSignerSecretStore($store, self::CNPJ, $ownerA);
        $second = new CompanyScopedSignerSecretStore($store, self::CNPJ, $ownerB);

        self::assertSame('secret-a', $first->get('pfx/' . self::CNPJ)['password']);
        self::assertSame('secret-b', $second->get('pfx/' . self::CNPJ)['password']);
        self::assertSame([$ownerA, $ownerB], $store->requests);
        self::assertNotContains('pfx/' . self::CNPJ, $store->requests);
    }

    public function testSignerRejectsAnotherCnpjAndRawVaultLookup(): void
    {
        $store = new class () implements SecretStoreInterface {
            public int $reads = 0;

            public function get(string $path): array
            {
                $this->reads++;

                return [];
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };

        $adapter = new CompanyScopedSignerSecretStore(
            $store,
            self::CNPJ,
            'pfx/companies/11/' . str_repeat('a', 32),
        );

        foreach (['pfx/99887766000155', 'pfx/companies/11/' . str_repeat('a', 32)] as $otherPath) {
            try {
                $adapter->get($otherPath);
                self::fail('Signer accepted an unapproved certificate identity.');
            } catch (SecretStoreException) {
                self::assertSame(0, $store->reads);
            }
        }
    }

    public function testRejectsLegacyVaultPathAtConstruction(): void
    {
        $store = new class () implements SecretStoreInterface {
            public function get(string $path): array
            {
                return [];
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        new CompanyScopedSignerSecretStore($store, self::CNPJ, 'pfx/' . self::CNPJ);
    }

    public function testSignerCannotWriteOrDeleteSecrets(): void
    {
        $store = new class () implements SecretStoreInterface {
            public function get(string $path): array
            {
                return [];
            }

            public function put(string $path, array $data): void
            {
                self::fail('Cannot mutate underlying Vault.');
            }

            public function delete(string $path): void
            {
                self::fail('Cannot delete from underlying Vault.');
            }
        };

        $adapter = new CompanyScopedSignerSecretStore(
            $store,
            self::CNPJ,
            'pfx/companies/11/' . str_repeat('a', 32),
        );

        try {
            $adapter->put('pfx/' . self::CNPJ, ['password' => 'changed']);
            self::fail('Signer accepted write.');
        } catch (SecretStoreException) {
            // Read-only by contract.
        }

        $this->expectException(SecretStoreException::class);
        $adapter->delete('pfx/' . self::CNPJ);
    }
}
