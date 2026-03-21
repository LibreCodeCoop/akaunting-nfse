<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Abstracts\Http {
    abstract class Controller
    {
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
}
