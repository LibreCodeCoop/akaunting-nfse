<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/ControllerIsolationState.php';

    if (!class_exists(\Illuminate\Http\UploadedFile::class, false)) {
        eval('namespace Illuminate\\Http; class UploadedFile { public function __construct(private string|false $realPath) {} public function getRealPath(): string|false { return $this->realPath; } }');
    }
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
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
    }
}
