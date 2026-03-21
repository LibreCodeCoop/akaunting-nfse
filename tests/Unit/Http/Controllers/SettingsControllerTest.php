<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/ControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\UploadedFile;
    use LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Http\Controllers\SettingsController;

    use function Modules\Nfse\Http\Controllers\storage_path;

    use Modules\Nfse\Tests\TestCase;

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

        public function testReplaceCertificateCyclePurgesOldArtifactsClearsSettingsAndStoresNewCertificate(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '11111111000111',
                'nfse.bao_addr' => 'http://openbao:8200',
                'nfse.item_lista_servico' => '0123',
            ];

            $oldStoragePath = storage_path('app/nfse/pfx/11111111000111.pfx');
            if (!is_dir(dirname($oldStoragePath))) {
                mkdir(dirname($oldStoragePath), 0o777, true);
            }
            file_put_contents($oldStoragePath, 'old-pfx');

            $secretStore = new class () implements SecretStoreInterface {
                /** @var list<string> */
                public array $deletedPaths = [];

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
                    $this->deletedPaths[] = $path;
                }
            };

            $controller = new class ($secretStore) extends SettingsController {
                public function __construct(private readonly SecretStoreInterface $secretStore)
                {
                }

                public function runReplaceCycle(string $previousCnpj, string $newCnpj, string $content, string $password): void
                {
                    $this->purgeCertificateArtifacts($previousCnpj);
                    $this->clearNfseSettings();
                    $this->storeCertificate($newCnpj, $content, $password);
                }

                protected function makeSecretStore(): SecretStoreInterface
                {
                    return $this->secretStore;
                }
            };

            $controller->runReplaceCycle('11111111000111', '22222222000122', 'new-pfx', 'new-password');

            $newStoragePath = storage_path('app/nfse/pfx/22222222000122.pfx');

            self::assertFileDoesNotExist($oldStoragePath);
            self::assertFileExists($newStoragePath);
            self::assertSame('new-pfx', file_get_contents($newStoragePath));
            self::assertSame('0600', substr(sprintf('%o', fileperms($newStoragePath)), -4));
            self::assertSame([], ControllerIsolationState::$settings);
            self::assertSame(['pfx/11111111000111'], $secretStore->deletedPaths);
            self::assertSame([
                [
                    'path' => 'pfx/22222222000122',
                    'data' => [
                        'pfx_path' => $newStoragePath,
                        'password' => 'new-password',
                    ],
                ],
            ], $secretStore->putCalls);
        }

        public function testPurgeCertificateArtifactsIgnoresSecretStoreErrors(): void
        {
            ControllerIsolationState::reset();

            $oldStoragePath = storage_path('app/nfse/pfx/11111111000111.pfx');
            if (!is_dir(dirname($oldStoragePath))) {
                mkdir(dirname($oldStoragePath), 0o777, true);
            }
            file_put_contents($oldStoragePath, 'old-pfx');

            $secretStore = new class () implements SecretStoreInterface {
                public function get(string $path): array
                {
                    return [];
                }

                public function put(string $path, array $data): void
                {
                }

                public function delete(string $path): void
                {
                    throw new \RuntimeException('secret store unavailable');
                }
            };

            $controller = new class ($secretStore) extends SettingsController {
                public function __construct(private readonly SecretStoreInterface $secretStore)
                {
                }

                public function runPurgeCertificateArtifacts(string $cnpj): void
                {
                    $this->purgeCertificateArtifacts($cnpj);
                }

                protected function makeSecretStore(): SecretStoreInterface
                {
                    return $this->secretStore;
                }
            };

            $controller->runPurgeCertificateArtifacts('11111111000111');

            self::assertFileDoesNotExist($oldStoragePath);
        }

        public function testReadUploadedCertificateThrowsWhenTemporaryFilePathIsNoLongerValid(): void
        {
            $uploadedFile = new UploadedFile(false);

            $controller = new class () extends SettingsController {
                public function runReadUploadedCertificate(UploadedFile $file): string
                {
                    return $this->readUploadedCertificate($file);
                }
            };

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid uploaded PFX path.');

            $controller->runReadUploadedCertificate($uploadedFile);
        }

        public function testUpdateReturnsBackWithInputAndInvalidPfxMessageWhenCertificateValidationFails(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '11111111000111',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => 'nfse',
                    ],
                    'pfx_password' => 'invalid',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-invalid.pfx'),
                ],
            );

            $controller = new class () extends SettingsController {
                protected function readUploadedCertificate(UploadedFile $file): string
                {
                    return 'fake-pfx-content';
                }

                protected function validateCertificatePayload(string $pfxContent, string $password): void
                {
                    throw new \RuntimeException('invalid cert');
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('back', $response->target);
            self::assertTrue($response->withInputCalled);
            self::assertSame('nfse::general.invalid_pfx', $response->flash['error'] ?? null);
        }

        public function testUpdateReturnsStoreFailureMessageAfterSettingsSaveWhenCertificateStoreThrows(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '22222222000122',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                    ],
                    'pfx_password' => 'valid-password',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-valid.pfx'),
                ],
            );

            $controller = new class () extends SettingsController {
                protected function readUploadedCertificate(UploadedFile $file): string
                {
                    return 'fake-pfx-content';
                }

                protected function validateCertificatePayload(string $pfxContent, string $password): void
                {
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                    throw new \RuntimeException('store failed');
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.certificate_store_failed', $response->flash['error'] ?? null);
            self::assertSame(1, ControllerIsolationState::$savedCount);
            self::assertSame('22222222000122', ControllerIsolationState::$settings['nfse.cnpj_prestador'] ?? null);
        }
    }
}
