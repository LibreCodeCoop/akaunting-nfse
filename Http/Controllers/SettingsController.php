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
use Modules\Nfse\Models\CompanyService;
use Modules\Nfse\Support\BrazilianStates;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;
use Throwable;

class SettingsController extends Controller
{
    private const IBGE_BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';

    public function edit(?Request $request = null): \Illuminate\View\View
    {
        if ($request === null && function_exists('request')) {
            $resolvedRequest = request();

            if ($resolvedRequest instanceof Request) {
                $request = $resolvedRequest;
            }
        }

        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];
        $certificateState = $this->certificateState();
        $vaultUiState = $this->vaultUiState($settingsArray, $certificateState);

        $rawTab = $request !== null ? $request->query('tab') : null;
        $activeTab = (is_string($rawTab) && in_array($rawTab, ['vault', 'certificate', 'fiscal', 'federal', 'services'], true))
            ? $rawTab
            : 'vault';

        $servicesSearch = $request !== null ? trim((string) $request->query('services_search', '')) : '';
        $servicesStatus = $request !== null ? (string) $request->query('services_status', 'all') : 'all';

        if (!in_array($servicesStatus, ['all', 'active', 'inactive'], true)) {
            $servicesStatus = 'all';
        }

        $companyId = 0;

        if ($request !== null && method_exists($request, 'route')) {
            $routeCompanyId = $request->route('company_id');
            $companyId = is_numeric($routeCompanyId) ? (int) $routeCompanyId : 0;
        }

        if ($companyId <= 0 && function_exists('company_id')) {
            $companyId = (int) (company_id() ?? 0);
        }

        if ($companyId <= 0 && function_exists('auth')) {
            $user = null;

            try {
                $user = auth()->user();
            } catch (Throwable) {
                $user = null;
            }

            if ($user !== null && isset($user->company_id)) {
                $companyId = (int) $user->company_id;
            }
        }

        $companyServices = [];
        $defaultCompanyService = null;

        if ($companyId > 0 && method_exists(CompanyService::class, 'query')) {
            $query = CompanyService::where('company_id', $companyId);

            if ($servicesSearch !== '') {
                $like = '%' . $servicesSearch . '%';

                $query->where(function ($builder) use ($like): void {
                    $builder->where('item_lista_servico', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            }

            if ($servicesStatus === 'active') {
                $query->where('is_active', true);
            }

            if ($servicesStatus === 'inactive') {
                $query->where('is_active', false);
            }

            $companyServices = $query
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at')
                ->get();

            foreach ($companyServices as $companyService) {
                if (($companyService->is_default ?? false) && ($companyService->is_active ?? true)) {
                    $defaultCompanyService = $companyService;
                    break;
                }
            }
        }

        return view('nfse::settings.edit', [
            'settings' => $settingsArray,
            'certificateState' => $certificateState,
            'vaultUiState' => $vaultUiState,
            'activeTab' => $activeTab,
            'companyServices' => $companyServices,
            'defaultCompanyService' => $defaultCompanyService,
            'servicesSearch' => $servicesSearch,
            'servicesStatus' => $servicesStatus,
        ]);
    }

    public function updateVault(Request $request): RedirectResponse
    {
        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $nfseInput = $this->prepareNfseInput($rawNfseInput);

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
            'nfse.tributacao_federal_mode' => 'required|in:per_invoice_amounts,percentage_profile',
            'nfse.federal_piscofins_situacao_tributaria' => 'nullable|regex:/^\d+$/',
            'nfse.federal_piscofins_tipo_retencao' => 'nullable|regex:/^\d+$/',
            'nfse.federal_piscofins_base_calculo' => 'nullable|numeric|min:0',
            'nfse.federal_piscofins_aliquota_pis' => 'nullable|numeric|min:0|max:100',
            'nfse.federal_piscofins_valor_pis' => 'nullable|numeric|min:0',
            'nfse.federal_piscofins_aliquota_cofins' => 'nullable|numeric|min:0|max:100',
            'nfse.federal_piscofins_valor_cofins' => 'nullable|numeric|min:0',
            'nfse.federal_valor_irrf' => 'nullable|numeric|min:0',
            'nfse.federal_valor_csll' => 'nullable|numeric|min:0',
            'nfse.federal_valor_cp' => 'nullable|numeric|min:0',
            'nfse.tributos_fed_p' => 'nullable|numeric|min:0|max:100',
            'nfse.tributos_est_p' => 'nullable|numeric|min:0|max:100',
            'nfse.tributos_mun_p' => 'nullable|numeric|min:0|max:100',
            'nfse.tributos_fed_sn' => 'nullable|numeric|min:0|max:100',
            'nfse.tributos_est_sn' => 'nullable|numeric|min:0|max:100',
            'nfse.tributos_mun_sn' => 'nullable|numeric|min:0|max:100',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];

        $keys = [
            'tributacao_federal_mode',
            'federal_piscofins_situacao_tributaria',
            'federal_piscofins_tipo_retencao',
            'federal_piscofins_base_calculo',
            'federal_piscofins_aliquota_pis',
            'federal_piscofins_valor_pis',
            'federal_piscofins_aliquota_cofins',
            'federal_piscofins_valor_cofins',
            'federal_valor_irrf',
            'federal_valor_csll',
            'federal_valor_cp',
            'tributos_fed_p',
            'tributos_est_p',
            'tributos_mun_p',
            'tributos_fed_sn',
            'tributos_est_sn',
            'tributos_mun_sn',
        ];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $rawNfseInput)) {
                continue;
            }

            $value = $rawNfseInput[$key];

            if (in_array($key, ['federal_piscofins_situacao_tributaria', 'federal_piscofins_tipo_retencao'], true)) {
                $value = trim((string) $value);
                $value = preg_match('/^\d+$/', $value) === 1 ? $value : null;
            } elseif ($key !== 'tributacao_federal_mode') {
                $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

                if ($value === '' || $value === null) {
                    $value = null;
                } elseif (is_numeric($value)) {
                    $value = number_format((float) $value, 2, '.', '');
                }
            }

            setting(['nfse.' . $key => $value]);
        }

        setting()->save();

        return redirect()->route('nfse.settings.edit', ['tab' => 'federal'])
            ->with('success', trans('nfse::general.saved'));
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
