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
use Modules\Nfse\Support\BrazilianStates;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\WebDavClient;
use Throwable;

class SettingsController extends Controller
{
    private const IBGE_BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';
    private const BRASIL_API_BASE_URL = 'https://brasilapi.com.br/api';

    public function edit(?Request $request = null): \Illuminate\View\View
    {
        if ($request === null && function_exists('request')) {
            try {
                $resolvedRequest = request();

                if ($resolvedRequest instanceof Request) {
                    $request = $resolvedRequest;
                }
            } catch (\Throwable) {
                // Keep deterministic behavior in isolated unit tests without a bound request instance.
            }
        }

        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];
        $certificateState = $this->certificateState();
        $vaultUiState = $this->vaultUiState($settingsArray, $certificateState);

        $rawTab = $request !== null ? $request->query('tab') : null;
        $activeTab = (is_string($rawTab) && in_array($rawTab, ['vault', 'certificate', 'fiscal', 'federal', 'artifacts'], true))
            ? $rawTab
            : 'vault';

        return view('nfse::settings.edit', [
            'settings' => $settingsArray,
            'certificateState' => $certificateState,
            'vaultUiState' => $vaultUiState,
            'activeTab' => $activeTab,
        ]);
    }

    public function updateVault(Request $request): RedirectResponse
    {
        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $nfseInput = $this->prepareNfseInput($rawNfseInput);

        $authModeUi = (string) $request->input('auth_mode_ui', '');
        if ($authModeUi === 'token') {
            $nfseInput['bao_role_id'] = '';
            $nfseInput['bao_secret_id'] = '';
        } elseif ($authModeUi === 'approle') {
            $nfseInput['bao_token'] = '';
        }

        $request->validate([
            'nfse.bao_addr'            => 'required|url',
            'nfse.bao_mount'           => 'required|string',
            'nfse.bao_token'           => 'nullable|string',
            'nfse.bao_role_id'         => 'nullable|string',
            'nfse.bao_secret_id'       => 'nullable|string',
            'nfse.clear_bao_token'     => 'nullable|boolean',
            'nfse.clear_bao_secret_id' => 'nullable|boolean',
        ]);

        foreach (['bao_addr', 'bao_mount', 'bao_token', 'bao_role_id', 'bao_secret_id'] as $key) {
            if (array_key_exists($key, $nfseInput)) {
                setting(['nfse.' . $key => $nfseInput[$key]]);
            }
        }

        setting()->save();

        return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
            ->with('success', trans('nfse::general.vault_saved_continue'));
    }

    public function updateFiscal(Request $request): RedirectResponse
    {
        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];

        if (!$this->isVaultReady($settingsArray)) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.cnpj_prestador'     => 'required|string|size:14',
            'nfse.uf'                 => 'required|string|size:2',
            'nfse.municipio_nome'     => 'required|string|max:255',
            'nfse.municipio_ibge'     => 'required|string|size:7',
            'nfse.opcao_simples_nacional' => 'nullable|in:1,2',
            'nfse.sandbox_mode'       => 'nullable|boolean',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];

        $fiscalInput = $rawNfseInput;
        $fiscalInput['uf'] = strtoupper((string) ($fiscalInput['uf'] ?? ''));

        foreach (['cnpj_prestador', 'uf', 'municipio_nome', 'municipio_ibge', 'opcao_simples_nacional', 'sandbox_mode'] as $key) {
            if (array_key_exists($key, $fiscalInput)) {
                setting(['nfse.' . $key => $fiscalInput[$key]]);
            }
        }

        setting()->save();

        return redirect()->route('nfse.settings.edit', ['tab' => 'fiscal'])
            ->with('success', trans('nfse::general.saved'));
    }

    public function updateFederal(Request $request): RedirectResponse
    {
        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];

        if (!$this->isVaultReady($settingsArray)) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.federal_piscofins_situacao_tributaria' => 'nullable|regex:/^\d+$/',
            'nfse.federal_piscofins_tipo_retencao' => 'nullable|regex:/^\d+$/',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $situacaoTributaria = trim((string) ($rawNfseInput['federal_piscofins_situacao_tributaria'] ?? ''));
        $tipoRetencao = trim((string) ($rawNfseInput['federal_piscofins_tipo_retencao'] ?? ''));

        setting([
            'nfse.federal_piscofins_situacao_tributaria' => preg_match('/^\d+$/', $situacaoTributaria) === 1 ? $situacaoTributaria : null,
            'nfse.federal_piscofins_tipo_retencao' => preg_match('/^\d+$/', $tipoRetencao) === 1 ? $tipoRetencao : null,
        ]);

        foreach ([
            'tributacao_federal_mode',
            'federal_piscofins_aliquota_pis',
            'federal_piscofins_aliquota_cofins',
            'federal_valor_irrf',
            'federal_valor_csll',
            'federal_valor_cp',
            'tributos_fed_p',
            'tributos_est_p',
            'tributos_mun_p',
            'tributos_fed_sn',
            'tributos_est_sn',
            'tributos_mun_sn',
            'federal_piscofins_base_calculo',
            'federal_piscofins_valor_pis',
            'federal_piscofins_valor_cofins',
        ] as $deprecatedKey) {
            setting()->forget('nfse.' . $deprecatedKey);
        }

        setting()->save();

        return redirect()->route('nfse.settings.edit', ['tab' => 'federal'])
            ->with('success', trans('nfse::general.saved'));
    }

    public function updateArtifacts(Request $request): RedirectResponse
    {
        $request->validate([
            'nfse.webdav_url' => 'nullable|url',
            'nfse.webdav_username' => 'nullable|string',
            'nfse.webdav_password' => 'nullable|string',
            'nfse.webdav_path_template' => 'nullable|string|max:255',
            'nfse.webdav_filename_template' => 'nullable|string|max:255',
            'nfse.webdav_store_xml' => 'nullable',
            'nfse.webdav_store_pdf' => 'nullable',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];

        $webDavUrl = trim((string) ($rawNfseInput['webdav_url'] ?? ''));
        $webDavUsername = trim((string) ($rawNfseInput['webdav_username'] ?? ''));
        $webDavPassword = trim((string) ($rawNfseInput['webdav_password'] ?? ''));
        $webDavPathTemplate = trim((string) ($rawNfseInput['webdav_path_template'] ?? ''));
        $webDavFileNameTemplate = trim((string) ($rawNfseInput['webdav_filename_template'] ?? ''));
        $storeXmlArtifacts = $this->toBooleanInput($rawNfseInput, 'webdav_store_xml', true);
        $storePdfArtifacts = $this->toBooleanInput($rawNfseInput, 'webdav_store_pdf', true);

        if ($webDavPathTemplate === '') {
            $webDavPathTemplate = 'nfse/{cnpj}/{year}/{month}/{day}';
        }

        if ($webDavFileNameTemplate === '') {
            $webDavFileNameTemplate = '{chave_acesso}';
        }

        if ($webDavUrl !== '') {
            try {
                $this->assertWebDavConnection($webDavUrl, $webDavUsername, $webDavPassword);
            } catch (Throwable $throwable) {
                return redirect()->route('nfse.settings.edit', ['tab' => 'artifacts'])
                    ->withInput()
                    ->with('error', trans('nfse::general.settings.artifacts.connection_failed', ['message' => $throwable->getMessage()]));
            }
        }

        setting(['nfse.webdav_url' => $webDavUrl]);
        setting(['nfse.webdav_username' => $webDavUsername]);
        setting(['nfse.webdav_password' => $webDavPassword]);
        setting(['nfse.webdav_path_template' => $webDavPathTemplate]);
        setting(['nfse.webdav_filename_template' => $webDavFileNameTemplate]);
        setting(['nfse.webdav_store_xml' => $storeXmlArtifacts]);
        setting(['nfse.webdav_store_pdf' => $storePdfArtifacts]);

        setting()->save();

        if ($webDavUrl !== '') {
            return redirect()->route('nfse.settings.edit', ['tab' => 'artifacts'])
                ->with('success', trans('nfse::general.settings.artifacts.webdav_configured'));
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'artifacts'])
            ->with('info', trans('nfse::general.settings.artifacts.webdav_disabled'));
    }

    protected function assertWebDavConnection(string $url, string $username, string $password): void
    {
        $client = $this->makeWebDavClient($url, $username, $password);

        // Non-mutating probes: authenticate against the base endpoint and verify random path access.
        $client->exists('');
        $client->exists('.nfse-connection-probe-' . bin2hex(random_bytes(6)));
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function toBooleanInput(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $rawValue = $input[$key];

        if (is_bool($rawValue)) {
            return $rawValue;
        }

        if (is_numeric($rawValue)) {
            return (int) $rawValue === 1;
        }

        $normalized = strtolower(trim((string) $rawValue));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    protected function makeWebDavClient(string $url, string $username, string $password): WebDavClient
    {
        return new WebDavClient(
            baseUrl: $url,
            username: $username,
            password: $password,
        );
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
                'data' => (new BrazilianStates())->all(),
                'message' => 'Using local fallback list because IBGE is unavailable.',
            ]);
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
            try {
                $rows = $this->fetchMunicipalitiesRowsFallback($normalizedUf);

                return $this->jsonResponse([
                    'data' => $ibgeLocalities->mapMunicipalities(is_array($rows) ? $rows : []),
                    'message' => 'Using fallback municipalities source because IBGE is unavailable.',
                ]);
            } catch (Throwable) {
                // Keep endpoint stable for UI even if all providers are unavailable.
                return $this->jsonResponse([
                    'data' => [],
                    'message' => 'Failed to load municipalities from IBGE and fallback source.',
                ]);
            }
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

        $isExplicitlyClearingCredentials = in_array((string) ($rawNfseInput['clear_bao_token'] ?? ''), ['1', 'true', 'on'], true)
            || in_array((string) ($rawNfseInput['clear_bao_secret_id'] ?? ''), ['1', 'true', 'on'], true);

        if (!$isExplicitlyClearingCredentials && !$vaultReadyAfterSubmission) {
            return redirect()->route('nfse.settings.edit')
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.cnpj_prestador'  => 'required|string|size:14',
            'nfse.uf'              => 'required|string|size:2',
            'nfse.municipio_nome'  => 'required|string|max:255',
            'nfse.municipio_ibge'  => 'required|string|size:7',
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
        $nfseInput['codigo_tributacao_nacional'] = preg_replace('/\D/', '', (string) ($nfseInput['codigo_tributacao_nacional'] ?? ''));
        $nfseInput['bao_mount'] = VaultConfig::normalizeMount((string) ($nfseInput['bao_mount'] ?? ''));
        unset($nfseInput['clear_bao_token'], $nfseInput['clear_bao_secret_id']);
        unset($nfseInput['item_lista_servico_display']);

        if (($nfseInput['codigo_tributacao_nacional'] ?? '') === '') {
            unset($nfseInput['codigo_tributacao_nacional']);
        }

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
        if ($approleComplete) {
            $authMode = 'approle';
        } elseif ($tokenConfigured) {
            $authMode = 'token';
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
            ->retry(2, 150)
            ->acceptJson()
            ->get(self::IBGE_BASE_URL . '/estados/' . $normalizedUf . '/municipios')
            ->throw()
            ->json();

        return is_array($rows) ? $rows : [];
    }

    protected function fetchMunicipalitiesRowsFallback(string $normalizedUf): array
    {
        $rows = Http::timeout(8)
            ->retry(2, 150)
            ->acceptJson()
            ->get(self::BRASIL_API_BASE_URL . '/ibge/municipios/v1/' . $normalizedUf, [
                'providers' => 'dados-abertos-br,gov',
            ])
            ->throw()
            ->json();

        if (!is_array($rows)) {
            return [];
        }

        $normalizedRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalizedRows[] = [
                'id' => $row['codigo_ibge'] ?? '',
                'nome' => $row['nome'] ?? '',
            ];
        }

        return $normalizedRows;
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
