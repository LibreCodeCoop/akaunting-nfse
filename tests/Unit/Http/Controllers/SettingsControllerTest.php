<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Support/ControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers {
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\UploadedFile;
    use LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Http\Controllers\SettingsController;

    use function Modules\Nfse\Http\Controllers\storage_path;

    use Modules\Nfse\Support\IbgeLocalities;
    use Modules\Nfse\Tests\TestCase;

    final class SettingsControllerTest extends TestCase
    {
        public function testEditReturnsSettingsAndCertificateStateIncludingLocalCertificatePresence(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000190',
                'nfse.uf' => 'RJ',
            ];

            $localCertificatePath = storage_path('app/nfse/pfx/12345678000190.pfx');
            if (!is_dir(dirname($localCertificatePath))) {
                mkdir(dirname($localCertificatePath), 0o777, true);
            }
            file_put_contents($localCertificatePath, 'fake-pfx');

            $response = (new SettingsController())->edit();

            self::assertSame('nfse::settings.edit', $response->name);
            self::assertSame([
                'cnpj_prestador' => '12345678000190',
                'uf' => 'RJ',
            ], $response->data['settings'] ?? []);
            self::assertSame('12345678000190', $response->data['certificateState']['cnpj'] ?? null);
            self::assertSame($localCertificatePath, $response->data['certificateState']['local_path'] ?? null);
            self::assertTrue($response->data['certificateState']['has_local_certificate'] ?? false);
            self::assertTrue($response->data['certificateState']['has_saved_settings'] ?? false);
            self::assertSame('incomplete', $response->data['vaultUiState']['auth_mode'] ?? null);
            self::assertFalse($response->data['vaultUiState']['token_configured'] ?? true);
        }

        public function testEditReturnsEmptyCertificateStateWhenNoSavedCnpjExists(): void
        {
            ControllerIsolationState::reset();

            $response = (new SettingsController())->edit();

            self::assertSame('nfse::settings.edit', $response->name);
            self::assertSame([], $response->data['settings'] ?? []);
            self::assertSame('', $response->data['certificateState']['cnpj'] ?? null);
            self::assertSame('', $response->data['certificateState']['local_path'] ?? null);
            self::assertFalse($response->data['certificateState']['has_local_certificate'] ?? true);
            self::assertFalse($response->data['certificateState']['has_saved_settings'] ?? true);
            self::assertSame('incomplete', $response->data['vaultUiState']['auth_mode'] ?? null);
        }

        public function testEditReturnsSavedCnpjStateWithoutLocalCertificateWhenFileIsMissing(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '98765432000199',
                'nfse.uf' => 'SP',
            ];

            $response = (new SettingsController())->edit();

            self::assertSame('nfse::settings.edit', $response->name);
            self::assertSame([
                'cnpj_prestador' => '98765432000199',
                'uf' => 'SP',
            ], $response->data['settings'] ?? []);
            self::assertSame('98765432000199', $response->data['certificateState']['cnpj'] ?? null);
            self::assertSame(storage_path('app/nfse/pfx/98765432000199.pfx'), $response->data['certificateState']['local_path'] ?? null);
            self::assertFalse($response->data['certificateState']['has_local_certificate'] ?? true);
            self::assertTrue($response->data['certificateState']['has_saved_settings'] ?? false);
            self::assertSame('incomplete', $response->data['vaultUiState']['auth_mode'] ?? null);
        }

        public function testEditDetectsAuthModeAsAppRoleWhenTokenAndAppRoleAreBothConfigured(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.bao_token' => 'persisted-token',
                'nfse.bao_role_id' => 'role-id',
                'nfse.bao_secret_id' => 'secret-id',
            ];

            $response = (new SettingsController())->edit();

            self::assertSame('approle', $response->data['vaultUiState']['auth_mode'] ?? null);
            self::assertTrue($response->data['vaultUiState']['token_configured'] ?? false);
            self::assertTrue($response->data['vaultUiState']['approle_complete'] ?? false);
        }

        public function testEditDetectsAuthModeAsTokenWhenOnlyTokenIsConfigured(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.bao_token' => 'persisted-token',
                'nfse.bao_role_id' => '',
                'nfse.bao_secret_id' => '',
            ];

            $response = (new SettingsController())->edit();

            self::assertSame('token', $response->data['vaultUiState']['auth_mode'] ?? null);
            self::assertTrue($response->data['vaultUiState']['token_configured'] ?? false);
            self::assertFalse($response->data['vaultUiState']['approle_complete'] ?? true);
        }

        public function testPrepareNfseInputNormalizesFieldsAndDropsEmptySensitiveFields(): void
        {
            $controller = new class () extends SettingsController {
                /** @param array<string, mixed> $nfseInput */
                public function runPrepareNfseInput(array $nfseInput): array
                {
                    return $this->prepareNfseInput($nfseInput);
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
            ]);

            self::assertSame([
                'uf' => 'RJ',
                'item_lista_servico' => '0123',
                'bao_mount' => '/nfse',
                'bao_role_id' => 'role-id',
            ], $prepared);
        }

        public function testPrepareNfseInputAlwaysDropsEmptySensitiveFieldsEvenDuringCertificateReplacement(): void
        {
            $controller = new class () extends SettingsController {
                /** @param array<string, mixed> $nfseInput */
                public function runPrepareNfseInput(array $nfseInput): array
                {
                    return $this->prepareNfseInput($nfseInput);
                }
            };

            $prepared = $controller->runPrepareNfseInput([
                'uf' => 'sp',
                'item_lista_servico' => '14-14',
                'bao_mount' => '/vault/nfse/',
                'bao_token' => '',
                'bao_secret_id' => '',
            ]);

            self::assertSame('SP', $prepared['uf']);
            self::assertSame('1414', $prepared['item_lista_servico']);
            self::assertSame('/vault/nfse', $prepared['bao_mount']);
            self::assertArrayNotHasKey('bao_token', $prepared);
            self::assertArrayNotHasKey('bao_secret_id', $prepared);
        }

        public function testPrepareNfseInputCanExplicitlyClearSensitiveFieldsWhenRequested(): void
        {
            $controller = new class () extends SettingsController {
                /** @param array<string, mixed> $nfseInput */
                public function runPrepareNfseInput(array $nfseInput): array
                {
                    return $this->prepareNfseInput($nfseInput);
                }
            };

            $prepared = $controller->runPrepareNfseInput([
                'uf' => 'sp',
                'item_lista_servico' => '1414',
                'bao_mount' => '/nfse',
                'bao_token' => '',
                'bao_secret_id' => '',
                'clear_bao_token' => '1',
                'clear_bao_secret_id' => '1',
            ]);

            self::assertSame('', $prepared['bao_token']);
            self::assertSame('', $prepared['bao_secret_id']);
            self::assertArrayNotHasKey('clear_bao_token', $prepared);
            self::assertArrayNotHasKey('clear_bao_secret_id', $prepared);
        }

        public function testUfsReturnsMappedAndSortedDataWhenIbgeRowsAreAvailable(): void
        {
            $controller = new class () extends SettingsController {
                protected function fetchUfsRows(): array
                {
                    return [
                        ['sigla' => 'sp', 'nome' => 'Sao Paulo'],
                        ['sigla' => 'rj', 'nome' => 'Rio de Janeiro'],
                        ['sigla' => 'x', 'nome' => 'Invalido'],
                    ];
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->ufs(new IbgeLocalities());

            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'data' => [
                    ['uf' => 'RJ', 'name' => 'Rio de Janeiro'],
                    ['uf' => 'SP', 'name' => 'Sao Paulo'],
                ],
            ], $response->getData(true));
        }

        public function testUfsReturnsFallbackWhenIbgeRequestFails(): void
        {
            $controller = new class () extends SettingsController {
                protected function fetchUfsRows(): array
                {
                    throw new \RuntimeException('ibge unavailable');
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->ufs(new IbgeLocalities());

            self::assertSame(502, $response->getStatusCode());
            self::assertSame([
                'data' => [],
                'message' => 'Failed to load UFs from IBGE.',
            ], $response->getData(true));
        }

        public function testMunicipalitiesReturnsInvalidUfResponseWhenUfFormatIsInvalid(): void
        {
            $controller = new class () extends SettingsController {
                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->municipalities('r', new IbgeLocalities());

            self::assertSame(422, $response->getStatusCode());
            self::assertSame([
                'data' => [],
                'message' => 'Invalid UF.',
            ], $response->getData(true));
        }

        public function testMunicipalitiesReturnsMappedDataWhenIbgeRowsAreAvailable(): void
        {
            $controller = new class () extends SettingsController {
                public string $receivedUf = '';

                protected function fetchMunicipalitiesRows(string $normalizedUf): array
                {
                    $this->receivedUf = $normalizedUf;

                    return [
                        ['id' => '3303302', 'nome' => 'Niteroi'],
                        ['id' => '3304557', 'nome' => 'Rio de Janeiro'],
                        ['id' => '', 'nome' => 'Invalido'],
                    ];
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->municipalities('rj', new IbgeLocalities());

            self::assertSame('RJ', $controller->receivedUf);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'data' => [
                    ['ibge_code' => '3303302', 'name' => 'Niteroi'],
                    ['ibge_code' => '3304557', 'name' => 'Rio de Janeiro'],
                ],
            ], $response->getData(true));
        }

        public function testMunicipalitiesTrimsWhitespaceBeforeNormalizingUf(): void
        {
            $controller = new class () extends SettingsController {
                public string $receivedUf = '';

                protected function fetchMunicipalitiesRows(string $normalizedUf): array
                {
                    $this->receivedUf = $normalizedUf;

                    return [
                        ['id' => '3550308', 'nome' => 'Sao Paulo'],
                    ];
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->municipalities(' sp ', new IbgeLocalities());

            self::assertSame('SP', $controller->receivedUf);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'data' => [
                    ['ibge_code' => '3550308', 'name' => 'Sao Paulo'],
                ],
            ], $response->getData(true));
        }

        public function testMunicipalitiesReturnsFallbackWhenIbgeRequestFails(): void
        {
            $controller = new class () extends SettingsController {
                protected function fetchMunicipalitiesRows(string $normalizedUf): array
                {
                    throw new \RuntimeException('ibge unavailable');
                }

                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $response = $controller->municipalities('rj', new IbgeLocalities());

            self::assertSame(502, $response->getStatusCode());
            self::assertSame([
                'data' => [],
                'message' => 'Failed to load municipalities from IBGE.',
            ], $response->getData(true));
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

        public function testReadUploadedCertificateReturnsFileContentsWhenTemporaryFilePathIsValid(): void
        {
            $controller = new class () extends SettingsController {
                public function runReadUploadedCertificate(UploadedFile $file): string
                {
                    return $this->readUploadedCertificate($file);
                }
            };

            $path = tempnam(sys_get_temp_dir(), 'nfse-pfx-');
            self::assertNotFalse($path);

            file_put_contents($path, 'PFX-CONTENT');

            $uploadedFile = new UploadedFile(
                $path,
                'certificate.pfx',
                'application/x-pkcs12',
                null,
                true,
            );

            try {
                self::assertSame('PFX-CONTENT', $controller->runReadUploadedCertificate($uploadedFile));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        public function testClearNfseSettingsDoesNothingWhenNoNfseSettingsAreStored(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'other.key' => 'value',
            ];

            $controller = new class () extends SettingsController {
                public function runClearNfseSettings(): void
                {
                    $this->clearNfseSettings();
                }
            };

            $controller->runClearNfseSettings();

            self::assertSame([
                'other.key' => 'value',
            ], ControllerIsolationState::$settings);
        }

        public function testClearNfseSettingsForgetsOnlyNfseKeys(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '12345678000190',
                'nfse.uf' => 'RJ',
                'other.key' => 'value',
            ];

            $controller = new class () extends SettingsController {
                public function runClearNfseSettings(): void
                {
                    $this->clearNfseSettings();
                }
            };

            $controller->runClearNfseSettings();

            self::assertSame([
                'other.key' => 'value',
            ], ControllerIsolationState::$settings);
        }

        public function testUpdateAllowsVaultOnlySaveBeforeCertificateAndFiscalSettings(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                        'bao_token' => 'dev-only-root-token',
                    ],
                ],
            );

            $response = (new SettingsController())->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.vault_saved_continue', $response->flash['success'] ?? null);
            self::assertSame('http://openbao:8200', ControllerIsolationState::$settings['nfse.bao_addr'] ?? null);
            self::assertSame('/nfse', ControllerIsolationState::$settings['nfse.bao_mount'] ?? null);
            self::assertSame('dev-only-root-token', ControllerIsolationState::$settings['nfse.bao_token'] ?? null);
            self::assertArrayNotHasKey('nfse.cnpj_prestador', ControllerIsolationState::$settings);
        }

        public function testUpdateBlocksFullSettingsFlowWhenVaultIsNotReady(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '33333333000133',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                    ],
                ],
            );

            $response = (new SettingsController())->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.vault_required_before_certificate_and_settings', $response->flash['error'] ?? null);
            self::assertSame(0, ControllerIsolationState::$savedCount);
            self::assertArrayNotHasKey('nfse.cnpj_prestador', ControllerIsolationState::$settings);
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
                        'bao_token' => 'dev-only-root-token',
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

        public function testUpdateReplacingCertificatePurgesPreviousArtifactsAndClearsNfseSettings(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '11111111000111',
                'nfse.uf' => 'RJ',
                'other.key' => 'value',
            ];

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
                        'bao_token' => 'dev-only-root-token',
                    ],
                    'pfx_password' => 'valid-password',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-replace.pfx'),
                ],
            );

            $controller = new class () extends SettingsController {
                public ?string $purgedCnpj = null;
                public bool $clearCalled = false;

                protected function readUploadedCertificate(UploadedFile $file): string
                {
                    return 'fake-pfx-content';
                }

                protected function validateCertificatePayload(string $pfxContent, string $password): void
                {
                }

                protected function purgeCertificateArtifacts(string $cnpj): void
                {
                    $this->purgedCnpj = $cnpj;
                }

                protected function clearNfseSettings(): void
                {
                    $this->clearCalled = true;
                    parent::clearNfseSettings();
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.saved_and_certificate_uploaded', $response->flash['success'] ?? null);
            self::assertSame('11111111000111', $controller->purgedCnpj);
            self::assertTrue($controller->clearCalled);
            self::assertSame('value', ControllerIsolationState::$settings['other.key'] ?? null);
        }

        public function testUpdateReplacingCertificatePreservesStoredSensitiveFieldsWhenSubmittedBlank(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.cnpj_prestador' => '11111111000111',
                'nfse.uf' => 'RJ',
                'nfse.bao_token' => 'persisted-token',
                'nfse.bao_secret_id' => 'persisted-secret-id',
            ];

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
                        'bao_token' => '',
                        'bao_role_id' => 'role-id',
                        'bao_secret_id' => '',
                    ],
                    'pfx_password' => 'valid-password',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-replace-preserve-sensitive.pfx'),
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
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.saved_and_certificate_uploaded', $response->flash['success'] ?? null);
            self::assertSame('persisted-token', ControllerIsolationState::$settings['nfse.bao_token'] ?? null);
            self::assertSame('persisted-secret-id', ControllerIsolationState::$settings['nfse.bao_secret_id'] ?? null);
        }

        public function testUpdateReplacingCertificateDoesNotPurgeOrClearWhenPreviousCnpjIsMissing(): void
        {
            ControllerIsolationState::reset();
            ControllerIsolationState::$settings = [
                'nfse.uf' => 'RJ',
                'other.key' => 'value',
            ];

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '33333333000133',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                        'bao_token' => 'dev-only-root-token',
                    ],
                    'pfx_password' => 'valid-password',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-replace-no-prev.pfx'),
                ],
            );

            $controller = new class () extends SettingsController {
                public ?string $purgedCnpj = null;
                public bool $clearCalled = false;

                protected function readUploadedCertificate(UploadedFile $file): string
                {
                    return 'fake-pfx-content';
                }

                protected function validateCertificatePayload(string $pfxContent, string $password): void
                {
                }

                protected function purgeCertificateArtifacts(string $cnpj): void
                {
                    $this->purgedCnpj = $cnpj;
                }

                protected function clearNfseSettings(): void
                {
                    $this->clearCalled = true;
                    parent::clearNfseSettings();
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.saved_and_certificate_uploaded', $response->flash['success'] ?? null);
            self::assertNull($controller->purgedCnpj);
            self::assertFalse($controller->clearCalled);
            self::assertSame('value', ControllerIsolationState::$settings['other.key'] ?? null);
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
                        'bao_token' => 'dev-only-root-token',
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

        public function testUpdateReturnsSavedMessageWhenNoCertificateIsProvided(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '33333333000133',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                        'bao_token' => 'dev-only-root-token',
                    ],
                ],
            );

            $response = (new SettingsController())->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.saved', $response->flash['success'] ?? null);
            self::assertSame(1, ControllerIsolationState::$savedCount);
            self::assertSame('33333333000133', ControllerIsolationState::$settings['nfse.cnpj_prestador'] ?? null);
        }

        public function testUpdateReturnsSavedAndCertificateUploadedMessageWhenCertificateStoreSucceeds(): void
        {
            ControllerIsolationState::reset();

            $request = new Request(
                inputs: [
                    'nfse' => [
                        'cnpj_prestador' => '44444444000144',
                        'uf' => 'rj',
                        'municipio_nome' => 'Niteroi',
                        'municipio_ibge' => '3303302',
                        'item_lista_servico' => '0123',
                        'bao_addr' => 'http://openbao:8200',
                        'bao_mount' => '/nfse',
                        'bao_token' => 'dev-only-root-token',
                    ],
                    'pfx_password' => 'valid-password',
                ],
                files: [
                    'pfx_file' => new UploadedFile('/tmp/cert-success.pfx'),
                ],
            );

            $controller = new class () extends SettingsController {
                public bool $storeCalled = false;

                protected function readUploadedCertificate(UploadedFile $file): string
                {
                    return 'fake-pfx-content';
                }

                protected function validateCertificatePayload(string $pfxContent, string $password): void
                {
                }

                protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
                {
                    $this->storeCalled = true;
                }
            };

            $response = $controller->update($request);

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('route', $response->target);
            self::assertSame('nfse.settings.edit', $response->route);
            self::assertSame('nfse::general.saved_and_certificate_uploaded', $response->flash['success'] ?? null);
            self::assertTrue($controller->storeCalled);
            self::assertSame(1, ControllerIsolationState::$savedCount);
            self::assertSame('44444444000144', ControllerIsolationState::$settings['nfse.cnpj_prestador'] ?? null);
        }

        public function testLc116ServicesReturnsFilteredCatalogData(): void
        {
            $controller = new class () extends SettingsController {
                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $request = new Request([
                'q' => 'contabilidade',
                'limit' => 10,
            ]);

            $response = $controller->lc116Services($request, new \Modules\Nfse\Support\Lc116Catalog());

            self::assertSame(200, $response->getStatusCode());
            self::assertCount(1, $response->getData(true)['data']);
            self::assertSame('1719', $response->getData(true)['data'][0]['code']);
        }

        public function testLc116ServicesEnforcesMinimumLimitWhenProvidedLimitIsZero(): void
        {
            $controller = new class () extends SettingsController {
                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $request = new Request([
                'q' => '',
                'limit' => 0,
            ]);

            $response = $controller->lc116Services($request, new \Modules\Nfse\Support\Lc116Catalog());

            self::assertSame(200, $response->getStatusCode());
            self::assertCount(1, $response->getData(true)['data']);
        }

        public function testLc116ServicesUsesNullQueryWhenRequestQueryValueIsNotString(): void
        {
            $controller = new class () extends SettingsController {
                protected function jsonResponse(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };

            $request = new Request([
                'q' => ['unexpected'],
                'limit' => 2,
            ]);

            $response = $controller->lc116Services($request, new \Modules\Nfse\Support\Lc116Catalog());

            self::assertSame(200, $response->getStatusCode());
            self::assertCount(2, $response->getData(true)['data']);
            self::assertSame('0101', $response->getData(true)['data'][0]['code']);
        }
    }
}
