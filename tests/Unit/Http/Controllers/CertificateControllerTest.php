<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/ControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\UploadedFile;
    use LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
    use Modules\Nfse\Http\Controllers\CertificateController;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;

    use function Modules\Nfse\Http\Controllers\storage_path;

    use Modules\Nfse\Tests\TestCase;

    final class CertificateControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000195',
            ];
        }

        protected function tearDown(): void
        {
            $this->removeDirectory(ControllerIsolationState::$storageRoot);

            parent::tearDown();
        }

        public function testDestroyCleanupRemovesStoredCertificateSecretAndSettings(): void
        {
            ControllerIsolationState::$settings = [
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
            self::assertSame([], ControllerIsolationState::$settings);
            self::assertSame(1, ControllerIsolationState::$savedCount);
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

        public function testParsePfxReturnsInvalidPfxResponseWhenUploadedFileCannotBeRead(): void
        {
            $controller = new class () extends CertificateController {
                protected function readUploadedFile(UploadedFile $file): string
                {
                    throw new \RuntimeException('unreadable');
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->parsePfx(new Request(
                inputs: ['pfx_password' => 'secret'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => 'nfse::general.invalid_pfx'], $response->getData(true));
        }

        public function testParsePfxReturnsParsedCertificateData(): void
        {
            $controller = new class () extends CertificateController {
                protected function readUploadedFile(UploadedFile $file): string
                {
                    return 'pfx-binary';
                }

                protected function parseUploadedCertificate(string $pfxContent, string $password): array
                {
                    return [
                        'cnpj' => '12345678000195',
                        'valid_to' => '2027-03-21',
                    ];
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->parsePfx(new Request(
                inputs: ['pfx_password' => 'secret'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'data' => [
                    'cnpj' => '12345678000195',
                    'valid_to' => '2027-03-21',
                ],
            ], $response->getData(true));
        }

        public function testUploadReturnsBackWithErrorWhenCertificateValidationFails(): void
        {
            $controller = new class () extends CertificateController {
                protected function readUploadedFile(UploadedFile $file): string
                {
                    return 'pfx-binary';
                }

                protected function parseUploadedCertificate(string $pfxContent, string $password): array
                {
                    throw new \RuntimeException('invalid');
                }
            };

            $response = $controller->upload(new Request(
                inputs: ['pfx_password' => 'wrong'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame('back', $response->target);
            self::assertSame('nfse::general.invalid_pfx', $response->flash['error'] ?? null);
        }

        public function testUploadReturnsBackWithErrorWhenCnpjNotFoundInCertificate(): void
        {
            $controller = new class () extends CertificateController {
                protected function readUploadedFile(UploadedFile $file): string
                {
                    return 'pfx-binary';
                }

                protected function parseUploadedCertificate(string $pfxContent, string $password): array
                {
                    return ['valid_to' => '2027-03-21']; // Missing CNPJ
                }
            };

            $response = $controller->upload(new Request(
                inputs: ['pfx_password' => 'secret'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame('back', $response->target);
            self::assertSame('nfse::general.cnpj_not_found', $response->flash['error'] ?? null);
        }

        public function testUploadRedirectsBackWithErrorWhenSecretStoreFails(): void
        {
            $controller = new class () extends CertificateController {
                protected function readUploadedFile(UploadedFile $file): string
                {
                    return 'pfx-binary';
                }

                protected function parseUploadedCertificate(string $pfxContent, string $password): array
                {
                    return ['cnpj' => '12345678000195', 'valid_to' => '2027-03-21'];
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                    throw new \RuntimeException('Vault write failed');
                }
            };

            $response = $controller->upload(new Request(
                inputs: ['pfx_password' => 'secret'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame('back', $response->target);
            self::assertSame('nfse::general.certificate_store_failed', $response->flash['error'] ?? null);
        }

        public function testUploadStoresCertificateExtractsCnpjAndRedirectsOnSuccess(): void
        {
            ControllerIsolationState::$settings = [];
            $controller = new class () extends CertificateController {
                /** @var list<array{cnpj: string, content: string, password: string}> */
                public array $storeCalls = [];

                protected function readUploadedFile(UploadedFile $file): string
                {
                    return 'pfx-binary';
                }

                protected function parseUploadedCertificate(string $pfxContent, string $password): array
                {
                    return [
                        'cnpj' => '12345678000195',
                        'valid_to' => '2027-03-21',
                    ];
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                    $this->storeCalls[] = [
                        'cnpj' => $cnpj,
                        'content' => $pfxContent,
                        'password' => $password,
                    ];
                }
            };

            $response = $controller->upload(new Request(
                inputs: ['pfx_password' => 'secret'],
                files: ['pfx_file' => new UploadedFile('/tmp/ignored')],
            ));

            self::assertSame([
                [
                    'cnpj' => '12345678000195',
                    'content' => 'pfx-binary',
                    'password' => 'secret',
                ],
            ], $controller->storeCalls);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame(['tab' => 'certificate'], $response->parameters[0] ?? null);
            self::assertSame('nfse::general.certificate_uploaded', $response->flash['success'] ?? null);
            // Verify CNPJ was saved to settings and persist() was called
            self::assertSame('12345678000195', ControllerIsolationState::$settings['nfse.cnpj_prestador'] ?? null);
            self::assertSame(1, ControllerIsolationState::$savedCount);
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
