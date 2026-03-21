<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!class_exists(\App\Abstracts\Http\Controller::class, false)) {
        eval('namespace App\\Abstracts\\Http; abstract class Controller {}');
    }
}

namespace Modules\Nfse\Http\Controllers {
    final class CertificateControllerTestState
    {
        /** @var array<string, mixed> */
        public static array $settings = [];

        public static int $savedCount = 0;

        public static string $storageRoot = '';

        public static function reset(): void
        {
            self::$settings = [];
            self::$savedCount = 0;
            self::$storageRoot = sys_get_temp_dir() . '/nfse-certificate-controller-test-' . uniqid('', true);

            if (!is_dir(self::$storageRoot)) {
                mkdir(self::$storageRoot, 0o777, true);
            }
        }
    }

    final class CertificateControllerFakeSettings
    {
        public function forget(string $key): void
        {
            unset(CertificateControllerTestState::$settings[$key]);
        }

        public function save(): void
        {
            CertificateControllerTestState::$savedCount++;
        }
    }

    function setting(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return new CertificateControllerFakeSettings();
        }

        if ($key === 'nfse') {
            $prefix = 'nfse.';
            $values = [];

            foreach (CertificateControllerTestState::$settings as $settingKey => $value) {
                if (str_starts_with($settingKey, $prefix)) {
                    $values[substr($settingKey, strlen($prefix))] = $value;
                }
            }

            return $values === [] ? $default : $values;
        }

        return CertificateControllerTestState::$settings[$key] ?? $default;
    }

    function storage_path(string $path = ''): string
    {
        return rtrim(CertificateControllerTestState::$storageRoot, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
    use Modules\Nfse\Http\Controllers\CertificateController;
    use Modules\Nfse\Http\Controllers\CertificateControllerTestState;
    use Modules\Nfse\Http\Controllers\SettingsController;

    use function Modules\Nfse\Http\Controllers\storage_path;

    use Modules\Nfse\Tests\TestCase;

    final class CertificateControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            CertificateControllerTestState::reset();
        }

        protected function tearDown(): void
        {
            $this->removeDirectory(CertificateControllerTestState::$storageRoot);

            parent::tearDown();
        }

        public function testDestroyCleanupRemovesStoredCertificateSecretAndSettings(): void
        {
            CertificateControllerTestState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
                'nfse.bao_addr' => 'http://openbao:8200',
                'nfse.bao_mount' => '/nfse',
                'nfse.item_lista' => '01.01',
            ];

            $storagePath = storage_path('app/nfse/pfx/12345678000195.pfx');
            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0o777, true);
            }
            file_put_contents($storagePath, 'fixture');

            $secretStore = new class () implements SecretStoreInterface {
                /** @var list<string> */
                public array $deletedPaths = [];

                public function get(string $path): array
                {
                    return [];
                }

                public function put(string $path, array $data): void
                {
                }

                public function delete(string $path): void
                {
                    $this->deletedPaths[] = $path;
                }
            };

            $controller = new class ($secretStore) extends CertificateController {
                public function __construct(private readonly SecretStoreInterface $secretStore)
                {
                }

                public function runDestroyCleanup(string $cnpj): void
                {
                    $this->clearStoredCertificate($cnpj);
                    $this->clearNfseSettings();
                }

                protected function makeSecretStore(): SecretStoreInterface
                {
                    return $this->secretStore;
                }
            };

            $controller->runDestroyCleanup('12345678000195');

            self::assertFileDoesNotExist($storagePath);
            self::assertSame(['pfx/12345678000195'], $secretStore->deletedPaths);
            self::assertSame([], CertificateControllerTestState::$settings);
            self::assertSame(1, CertificateControllerTestState::$savedCount);
        }

        public function testStoreCertificatePersistsPrivateFileAndSecret(): void
        {
            $secretStore = new class () implements SecretStoreInterface {
                /** @var list<array{path: string, data: array<string, string>}> */
                public array $putCalls = [];

                public function get(string $path): array
                {
                    return [];
                }

                public function put(string $path, array $data): void
                {
                    $this->putCalls[] = [
                        'path' => $path,
                        'data' => $data,
                    ];
                }

                public function delete(string $path): void
                {
                }
            };

            $controller = new class ($secretStore) extends CertificateController {
                public function __construct(private readonly SecretStoreInterface $secretStore)
                {
                }

                public function runStoreCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                    $this->storeCertificate($cnpj, $pfxContent, $password);
                }

                protected function makeSecretStore(): SecretStoreInterface
                {
                    return $this->secretStore;
                }
            };

            $controller->runStoreCertificate('12345678000195', 'pfx-binary-fixture', 'secret-password');

            $storagePath = storage_path('app/nfse/pfx/12345678000195.pfx');

            self::assertFileExists($storagePath);
            self::assertSame('pfx-binary-fixture', file_get_contents($storagePath));
            self::assertSame('0600', substr(sprintf('%o', fileperms($storagePath)), -4));
            self::assertSame([
                [
                    'path' => 'pfx/12345678000195',
                    'data' => [
                        'pfx_path' => $storagePath,
                        'password' => 'secret-password',
                    ],
                ],
            ], $secretStore->putCalls);
        }

        private function removeDirectory(string $path): void
        {
            if ($path === '' || !is_dir($path)) {
                return;
            }

            $items = scandir($path);
            if ($items === false) {
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $path . '/' . $item;

                if (is_dir($itemPath)) {
                    $this->removeDirectory($itemPath);
                    continue;
                }

                unlink($itemPath);
            }

            rmdir($path);
        }
    }

    final class SettingsControllerTest extends TestCase
    {
        public function testPrepareNfseInputNormalizesFieldsAndPreservesExistingSecretsWithoutCertificateReplacement(): void
        {
            $controller = new class () extends SettingsController {
                /** @param array<string, mixed> $nfseInput */
                public function runPrepareNfseInput(array $nfseInput, bool $isReplacingCertificate): array
                {
                    return $this->prepareNfseInput($nfseInput, $isReplacingCertificate);
                }
            };

            $prepared = $controller->runPrepareNfseInput([
                'uf' => 'rj',
                'item_lista_servico' => '01.23',
                'item_lista_servico_display' => '01.23 - descricao',
                'bao_mount' => 'nfse',
                'bao_token' => '',
                'bao_secret_id' => '',
                'bao_role_id' => 'role-id',
            ], false);

            self::assertSame([
                'uf' => 'RJ',
                'item_lista_servico' => '0123',
                'bao_mount' => '/nfse',
                'bao_role_id' => 'role-id',
            ], $prepared);
        }

        public function testPrepareNfseInputKeepsEmptySensitiveFieldsDuringCertificateReplacement(): void
        {
            $controller = new class () extends SettingsController {
                /** @param array<string, mixed> $nfseInput */
                public function runPrepareNfseInput(array $nfseInput, bool $isReplacingCertificate): array
                {
                    return $this->prepareNfseInput($nfseInput, $isReplacingCertificate);
                }
            };

            $prepared = $controller->runPrepareNfseInput([
                'uf' => 'sp',
                'item_lista_servico' => '14-14',
                'bao_mount' => '/vault/nfse/',
                'bao_token' => '',
                'bao_secret_id' => '',
            ], true);

            self::assertSame('SP', $prepared['uf']);
            self::assertSame('1414', $prepared['item_lista_servico']);
            self::assertSame('/vault/nfse', $prepared['bao_mount']);
            self::assertArrayHasKey('bao_token', $prepared);
            self::assertArrayHasKey('bao_secret_id', $prepared);
            self::assertSame('', $prepared['bao_token']);
            self::assertSame('', $prepared['bao_secret_id']);
        }
    }
}
