<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;
use Throwable;

class SettingsController extends Controller
{
    private const IBGE_BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';

    public function edit(): \Illuminate\View\View
    {
        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];
        $certificateState = $this->certificateState();
        $vaultUiState = $this->vaultUiState($settingsArray, $certificateState);

        return view('nfse::settings.edit', [
            'settings' => $settingsArray,
            'certificateState' => $certificateState,
            'vaultUiState' => $vaultUiState,
        ]);
    }

    public function readiness(): \Illuminate\View\View
    {
        $settings = setting('nfse', []);
        $certificateState = $this->certificateState();
        $checklist = $this->readinessChecklist(is_array($settings) ? $settings : [], $certificateState);
        $isReady = !in_array(false, $checklist, true);

        return view('nfse::settings.readiness', compact('settings', 'certificateState', 'checklist', 'isReady'));
    }

    public function ufs(IbgeLocalities $ibgeLocalities): JsonResponse
    {
        try {
            $rows = $this->fetchUfsRows();

            return $this->jsonResponse([
                'data' => $ibgeLocalities->mapUfs(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Failed to load UFs from IBGE.',
            ], 502);
        }
    }

    public function municipalities(string $uf, IbgeLocalities $ibgeLocalities): JsonResponse
    {
        $normalizedUf = strtoupper(trim($uf));
        if (!preg_match('/^[A-Z]{2}$/', $normalizedUf)) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Invalid UF.',
            ], 422);
        }

        try {
            $rows = $this->fetchMunicipalitiesRows($normalizedUf);

            return $this->jsonResponse([
                'data' => $ibgeLocalities->mapMunicipalities(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Failed to load municipalities from IBGE.',
            ], 502);
        }
    }

    public function lc116Services(Request $request, Lc116Catalog $lc116Catalog): JsonResponse
    {
        $query = $request->query('q');
        $limit = (int) $request->query('limit', 200);

        return $this->jsonResponse([
            'data' => $lc116Catalog->search(is_string($query) ? $query : null, $limit),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $previousCnpj = (string) setting('nfse.cnpj_prestador', '');

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $nfseInput = $this->prepareNfseInput($rawNfseInput);

        $existingVaultSettings = [
            'bao_addr' => (string) setting('nfse.bao_addr', ''),
            'bao_mount' => (string) setting('nfse.bao_mount', ''),
            'bao_token' => (string) setting('nfse.bao_token', ''),
            'bao_role_id' => (string) setting('nfse.bao_role_id', ''),
            'bao_secret_id' => (string) setting('nfse.bao_secret_id', ''),
        ];

        $projectedVaultSettings = $this->projectVaultSettings($existingVaultSettings, $nfseInput);
        $vaultReadyAfterSubmission = $this->isVaultReady($projectedVaultSettings);

        $certificatePayload = null;
        $certificateFile = $request->file('pfx_file');
        $isReplacingCertificate = $certificateFile instanceof UploadedFile;
        $isVaultOnlySubmission = !$isReplacingCertificate && !array_key_exists('cnpj_prestador', $rawNfseInput);

        if ($isVaultOnlySubmission) {
            $request->validate([
                'nfse.bao_addr'        => 'required|url',
                'nfse.bao_mount'       => 'required|string',
                'nfse.bao_token'       => 'nullable|string',
                'nfse.bao_role_id'     => 'nullable|string',
                'nfse.bao_secret_id'   => 'nullable|string',
                'nfse.clear_bao_token' => 'nullable|boolean',
                'nfse.clear_bao_secret_id' => 'nullable|boolean',
            ]);

            foreach (['bao_addr', 'bao_mount', 'bao_token', 'bao_role_id', 'bao_secret_id'] as $key) {
                if (array_key_exists($key, $nfseInput)) {
                    setting(['nfse.' . $key => $nfseInput[$key]]);
                }
            }

            setting()->save();

            return redirect()->route('nfse.settings.edit')
                ->with('success', trans('nfse::general.vault_saved_continue'));
        }

        if (!$vaultReadyAfterSubmission) {
            return redirect()->route('nfse.settings.edit')
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.cnpj_prestador'  => 'required|string|size:14',
            'nfse.uf'              => 'required|string|size:2',
            'nfse.municipio_nome'  => 'required|string|max:255',
            'nfse.municipio_ibge'  => 'required|string|size:7',
            'nfse.item_lista_servico' => 'required|string|size:4',
            'nfse.sandbox_mode'    => 'nullable|boolean',
            'nfse.bao_addr'        => 'required|url',
            'nfse.bao_mount'       => 'required|string',
            'nfse.bao_token'       => 'nullable|string',
            'nfse.bao_role_id'     => 'nullable|string',
            'nfse.bao_secret_id'   => 'nullable|string',
            'nfse.clear_bao_token' => 'nullable|boolean',
            'nfse.clear_bao_secret_id' => 'nullable|boolean',
            'replace_certificate'  => 'nullable|boolean',
            'pfx_file'             => 'nullable|file|extensions:pfx,p12|max:1024',
            'pfx_password'         => 'nullable|string|max:255|required_with:pfx_file',
        ]);

        $existingSensitiveSettings = [
            'bao_token' => (string) setting('nfse.bao_token', ''),
            'bao_secret_id' => (string) setting('nfse.bao_secret_id', ''),
        ];

        // Replacement flow clears all nfse.* settings before persisting new values.
        // If sensitive fields are blank (keep current), re-inject old values so they survive the clear.
        foreach ($existingSensitiveSettings as $key => $value) {
            if (!array_key_exists($key, $nfseInput) && $value !== '') {
                $nfseInput[$key] = $value;
            }
        }

        if ($certificateFile instanceof UploadedFile) {
            try {
                $certificatePayload = [
                    'content' => $this->readUploadedCertificate($certificateFile),
                    'password' => (string) $request->input('pfx_password', ''),
                ];

                $this->validateCertificatePayload(
                    $certificatePayload['content'],
                    $certificatePayload['password'],
                );
            } catch (\RuntimeException) {
                return redirect()->back()->withInput()->with('error', trans('nfse::general.invalid_pfx'));
            }
        }

        if ($isReplacingCertificate && $previousCnpj !== '') {
            $this->purgeCertificateArtifacts($previousCnpj);
            $this->clearNfseSettings();
        }

        foreach ($nfseInput as $key => $value) {
            setting(['nfse.' . $key => $value]);
        }

        setting()->save();

        if (is_array($certificatePayload)) {
            try {
                $this->storeCertificate(
                    (string) $nfseInput['cnpj_prestador'],
                    $certificatePayload['content'],
                    $certificatePayload['password'],
                );

                return redirect()->route('nfse.settings.edit')
                    ->with('success', trans('nfse::general.saved_and_certificate_uploaded'));
            } catch (Throwable) {
                return redirect()->route('nfse.settings.edit')
                    ->with('error', trans('nfse::general.certificate_store_failed'));
            }
        }

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.saved'));
    }

    /**
     * @param array<string, mixed> $nfseInput
     * @return array<string, mixed>
     */
    protected function prepareNfseInput(array $nfseInput): array
    {
        $clearBaoToken = in_array((string) ($nfseInput['clear_bao_token'] ?? ''), ['1', 'true', 'on'], true);
        $clearBaoSecretId = in_array((string) ($nfseInput['clear_bao_secret_id'] ?? ''), ['1', 'true', 'on'], true);

        $nfseInput['uf'] = strtoupper((string) ($nfseInput['uf'] ?? ''));
        $nfseInput['item_lista_servico'] = preg_replace('/\D/', '', (string) ($nfseInput['item_lista_servico'] ?? ''));
        $nfseInput['bao_mount'] = VaultConfig::normalizeMount((string) ($nfseInput['bao_mount'] ?? ''));
        unset($nfseInput['clear_bao_token'], $nfseInput['clear_bao_secret_id']);
        unset($nfseInput['item_lista_servico_display']);

        if ($clearBaoToken) {
            $nfseInput['bao_token'] = '';
        } elseif (($nfseInput['bao_token'] ?? '') === '') {
            unset($nfseInput['bao_token']);
        }

        if ($clearBaoSecretId) {
            $nfseInput['bao_secret_id'] = '';
        } elseif (($nfseInput['bao_secret_id'] ?? '') === '') {
            unset($nfseInput['bao_secret_id']);
        }

        return $nfseInput;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $certificateState
     * @return array<string, bool|string>
     */
    protected function vaultUiState(array $settings, array $certificateState): array
    {
        $addrConfigured = ((string) ($settings['bao_addr'] ?? '')) !== '';
        $mountConfigured = ((string) ($settings['bao_mount'] ?? '')) !== '';
        $tokenConfigured = ((string) ($settings['bao_token'] ?? '')) !== '';
        $roleIdConfigured = ((string) ($settings['bao_role_id'] ?? '')) !== '';
        $secretIdConfigured = ((string) ($settings['bao_secret_id'] ?? '')) !== '';
        $approleComplete = $roleIdConfigured && $secretIdConfigured;

        $authMode = 'incomplete';
        if ($tokenConfigured) {
            $authMode = 'token';
        } elseif ($approleComplete) {
            $authMode = 'approle';
        }

        $cnpj = (string) ($settings['cnpj_prestador'] ?? ($certificateState['cnpj'] ?? ''));

        return [
            'addr_configured' => $addrConfigured,
            'mount_configured' => $mountConfigured,
            'token_configured' => $tokenConfigured,
            'role_id_configured' => $roleIdConfigured,
            'secret_id_configured' => $secretIdConfigured,
            'approle_complete' => $approleComplete,
            'auth_mode' => $authMode,
            'ready' => $this->isVaultReady($settings),
            'certificate_secret_available' => $this->hasCertificateSecret($cnpj),
        ];
    }

    /**
     * @param array<string, string> $currentVaultSettings
     * @param array<string, mixed> $nfseInput
     * @return array<string, string>
     */
    protected function projectVaultSettings(array $currentVaultSettings, array $nfseInput): array
    {
        $projected = $currentVaultSettings;

        foreach (['bao_addr', 'bao_mount', 'bao_token', 'bao_role_id', 'bao_secret_id'] as $vaultKey) {
            if (array_key_exists($vaultKey, $nfseInput)) {
                $projected[$vaultKey] = (string) $nfseInput[$vaultKey];
            }
        }

        return $projected;
    }

    /**
     * @param array<string, mixed> $vaultSettings
     */
    protected function isVaultReady(array $vaultSettings): bool
    {
        $hasAddress = ((string) ($vaultSettings['bao_addr'] ?? '')) !== '';
        $hasMount = ((string) ($vaultSettings['bao_mount'] ?? '')) !== '';
        $hasToken = ((string) ($vaultSettings['bao_token'] ?? '')) !== '';
        $hasAppRole = ((string) ($vaultSettings['bao_role_id'] ?? '')) !== ''
            && ((string) ($vaultSettings['bao_secret_id'] ?? '')) !== '';

        return $hasAddress && $hasMount && ($hasToken || $hasAppRole);
    }

    protected function fetchUfsRows(): array
    {
        $rows = Http::timeout(8)
            ->acceptJson()
            ->get(self::IBGE_BASE_URL . '/estados')
            ->throw()
            ->json();

        return is_array($rows) ? $rows : [];
    }

    protected function fetchMunicipalitiesRows(string $normalizedUf): array
    {
        $rows = Http::timeout(8)
            ->acceptJson()
            ->get(self::IBGE_BASE_URL . '/estados/' . $normalizedUf . '/municipios')
            ->throw()
            ->json();

        return is_array($rows) ? $rows : [];
    }

    protected function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    private function certificateState(): array
    {
        $cnpj = (string) setting('nfse.cnpj_prestador', '');
        $path = $cnpj !== '' ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx') : '';
        $hasLocalCertificate = $path !== '' && is_file($path);

        return [
            'cnpj' => $cnpj,
            'local_path' => $path,
            'has_local_certificate' => $hasLocalCertificate,
            'has_saved_settings' => $cnpj !== '',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $certificateState
     * @return array<string, bool>
     */
    protected function readinessChecklist(array $settings, array $certificateState): array
    {
        return [
            'cnpj_prestador' => ((string) ($settings['cnpj_prestador'] ?? '')) !== '',
            'municipio_ibge' => ((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => ((string) ($settings['item_lista_servico'] ?? '')) !== '',
            'bao_addr' => ((string) ($settings['bao_addr'] ?? '')) !== '',
            'bao_mount' => ((string) ($settings['bao_mount'] ?? '')) !== '',
            'certificate' => (bool) ($certificateState['has_local_certificate'] ?? false),
            'certificate_secret' => $this->hasCertificateSecret((string) ($settings['cnpj_prestador'] ?? '')),
        ];
    }

    protected function hasCertificateSecret(string $cnpj): bool
    {
        if ($cnpj === '') {
            return false;
        }

        try {
            $secret = $this->makeSecretStore()->get('pfx/' . $cnpj);

            return (($secret['password'] ?? '') !== '') && (($secret['pfx_path'] ?? '') !== '');
        } catch (Throwable) {
            return false;
        }
    }

    protected function readUploadedCertificate(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new \RuntimeException('Invalid uploaded PFX path.');
        }

        $content = file_get_contents($realPath);

        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded PFX.');
        }

        return $content;
    }

    protected function validateCertificatePayload(string $pfxContent, string $password): void
    {
        PfxReader::readCertificatePem($pfxContent, $password);
    }

    protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
    {
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0o700, true);
        }

        file_put_contents($storagePath, $pfxContent);
        chmod($storagePath, 0o600);

        $store = $this->makeSecretStore();
        $store->put('pfx/' . $cnpj, [
            'pfx_path' => $storagePath,
            'password' => $password,
        ]);
    }

    protected function purgeCertificateArtifacts(string $cnpj): void
    {
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (is_file($storagePath)) {
            unlink($storagePath);
        }

        try {
            $this->makeSecretStore()->delete('pfx/' . $cnpj);
        } catch (Throwable) {
            // Keep replacement flow resilient if old secret is already absent.
        }
    }

    protected function clearNfseSettings(): void
    {
        $nfseSettings = setting('nfse');

        if (!is_array($nfseSettings)) {
            return;
        }

        foreach (array_keys($nfseSettings) as $key) {
            setting()->forget('nfse.' . $key);
        }
    }

    protected function makeSecretStore(): \LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface
    {
        $config = VaultConfig::secretStoreConfig();

        return new \LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore(
            addr: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }
}
