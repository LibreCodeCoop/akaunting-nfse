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
use Illuminate\Validation\ValidationException;
use Modules\Nfse\Application\SimplesOptionCode;
use Modules\Nfse\Application\SpecialTaxRegimePolicy;
use Modules\Nfse\Models\ApproximateTaxSource;
use Modules\Nfse\Support\BrazilianStates;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\GlobalVaultSettings;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Support\MunicipalParameterConsultation;
use Modules\Nfse\Support\MunicipalParameterSnapshotStore;
use Modules\Nfse\Support\NfseCompanyFiscalSettings;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\VaultConfigurationUnreadable;
use Modules\Nfse\Support\VaultDiagnostics;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\MunicipalParametersConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\QueryException;
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

        // The admin screen only displays global infrastructure metadata.
        // Never resolve legacy certificate-by-CNPJ settings on this route.
        $vaultConfigurationUnreadable = false;
        try {
            $global = VaultConfig::secretStoreConfig();
        } catch (VaultConfigurationUnreadable) {
            $global = [
                'addr' => '', 'mount' => '', 'token' => null,
                'roleId' => null, 'secretId' => null,
            ];
            $vaultConfigurationUnreadable = true;
        }

        $vaultUiState = $this->vaultUiState($global);
        $vaultUiState['configuration_error'] = $vaultConfigurationUnreadable;

        $rawTab = $request !== null ? $request->query('tab') : null;
        $activeTab = (is_string($rawTab) && in_array($rawTab, ['vault', 'certificate', 'fiscal', 'federal', 'artifacts'], true))
            ? $rawTab
            : 'vault';

        // Do not pass fiscal settings, legacy certificate state, or plaintext
        // Vault credentials to the administrative view.
        return view('nfse::settings.edit', [
            'settings' => [
                'bao_addr' => $global['addr'],
                'bao_mount' => $global['mount'],
            ],
            'vaultUiState' => $vaultUiState,
            'activeTab' => $activeTab,
        ]);
    }

    public function updateVault(Request $request): RedirectResponse
    {
        $request->validate([
            'nfse.bao_addr' => 'required|url',
            'nfse.bao_mount' => 'required|string|max:160',
            'nfse.bao_token' => 'nullable|string',
            'nfse.bao_role_id' => 'nullable|string',
            'nfse.bao_secret_id' => 'nullable|string',
            'nfse.clear_bao_token' => 'nullable|boolean',
            'nfse.clear_bao_secret_id' => 'nullable|boolean',
            'auth_mode_ui' => 'required|in:token,approle',
        ]);

        $data = $request->input('nfse', []);
        $mode = (string) $request->input('auth_mode_ui');
        $input = [
            'addr' => (string) $data['bao_addr'],
            'mount' => VaultConfig::normalizeMount((string) $data['bao_mount']),
            'auth_mode' => $mode,
        ];

        foreach (['bao_token' => 'token', 'bao_role_id' => 'roleId', 'bao_secret_id' => 'secretId'] as $field => $key) {
            if (($mode === 'token' && $key !== 'token') || ($mode === 'approle' && $key === 'token')) {
                continue;
            }

            $raw = trim((string) ($data[$field] ?? ''));
            $clear = $key === 'token' ? 'clear_bao_token' : ($key === 'secretId' ? 'clear_bao_secret_id' : null);
            if ($raw !== '' || ($clear !== null && filter_var($data[$clear] ?? false, FILTER_VALIDATE_BOOL))) {
                $input[$key] = $raw !== '' ? $raw : null;
            }
        }

        try {
            $this->saveGlobalVaultSettings($input);
        } catch (\InvalidArgumentException) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', 'Vault configuration is incomplete.');
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
            ->with('success', trans('nfse::general.vault_saved_continue'));
    }

    /**
     * Override at the HTTP-controller boundary for isolated unit tests.
     *
     * @param array<string, mixed> $input
     */
    protected function saveGlobalVaultSettings(array $input): void
    {
        (new GlobalVaultSettings())->save($input);
    }

    public function diagnoseVault(VaultDiagnostics $diagnostics): JsonResponse
    {
        try {
            return response()->json($diagnostics->check(VaultConfig::secretStoreConfig()));
        } catch (VaultConfigurationUnreadable) {
            return response()->json([
                'state' => 'failed',
                'reason' => 'configuration_unreadable',
                'capabilities' => \Modules\Nfse\Support\VaultDiagnosticPolicy::emptyCapabilities(),
            ]);
        }
    }

    public function updateFiscal(Request $request): RedirectResponse
    {
        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];

        if (!$this->isVaultReady($this->globalVaultReadinessSettings())) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.cnpj_prestador'     => 'required|string|size:14',
            'nfse.uf'                 => 'required|string|size:2',
            'nfse.municipio_nome'     => 'required|string|max:255',
            'nfse.municipio_ibge'     => 'required|string|size:7',
            'nfse.prestador_telefone' => 'nullable|string|max:25|regex:/^[+()0-9 .-]+$/',
            'nfse.prestador_email' => 'nullable|email|max:255',
            'nfse.prestador_inscricao_municipal' => 'nullable|string|max:15',
            'nfse.provider_establishment_confirmed' => 'nullable|boolean',
            'nfse.opcao_simples_nacional' => 'nullable|in:1,2,3',
            'nfse.regime_especial_tributacao' => 'nullable|in:0,1,2,3,4,5,6,9',
            'nfse.regime_apuracao_simples_nacional' => 'nullable|in:1,2,3',
            'nfse.emission_policy' => 'nullable|in:manual,emit_on_send',
            'nfse.tributacao_issqn' => 'required|in:1,2,3,4',
            'nfse.tipo_retencao_iss' => 'required|in:1,2,3',
            'nfse.issqn_pais_resultado' => 'required_if:nfse.tributacao_issqn,3|nullable|regex:/^[A-Za-z]{2}$/|not_in:BR,br',
            'nfse.issqn_tipo_imunidade' => 'required_if:nfse.tributacao_issqn,2|nullable|in:1,2,3,4,5',
            'nfse.issqn_tipo_suspensao' => 'nullable|in:1,2|prohibited_unless:nfse.tributacao_issqn,1',
            'nfse.issqn_numero_processo_suspensao' => 'nullable|regex:/^\\d{30}$/|required_with:nfse.issqn_tipo_suspensao|prohibited_unless:nfse.tributacao_issqn,1',
            'nfse.sandbox_mode'       => 'nullable|boolean',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];

        $regime = $this->specialRegimeAssessment($rawNfseInput, $settingsArray);
        if ($regime['state'] === 'invalid') {
            return redirect()->back()->withInput()->with('error', $this->specialRegimeError($regime['reason']));
        }

        $fiscalInput = $rawNfseInput;
        if (array_key_exists('regime_especial_tributacao', $fiscalInput)) {
            $fiscalInput['regime_especial_tributacao'] = $regime['code'] !== null
                ? (string) $regime['code']
                : null;
        }
        $fiscalInput['uf'] = strtoupper((string) ($fiscalInput['uf'] ?? ''));
        $fiscalInput['issqn_pais_resultado'] = strtoupper(trim((string) ($fiscalInput['issqn_pais_resultado'] ?? '')));

        $tributacaoIssqn = (int) ($fiscalInput['tributacao_issqn'] ?? 1);
        if ($tributacaoIssqn !== 3) {
            $fiscalInput['issqn_pais_resultado'] = '';
        }
        if ($tributacaoIssqn !== 2) {
            $fiscalInput['issqn_tipo_imunidade'] = null;
        }
        if ($tributacaoIssqn !== 1) {
            $fiscalInput['issqn_tipo_suspensao'] = null;
            $fiscalInput['issqn_numero_processo_suspensao'] = '';
        }

        $previousCnpj = strtoupper(trim((string) setting('nfse.cnpj_prestador', '')));
        $previousMunicipality = trim((string) setting('nfse.municipio_ibge', ''));

        foreach ([
            'cnpj_prestador',
            'uf',
            'municipio_nome',
            'municipio_ibge',
            'prestador_telefone',
            'prestador_email',
            'prestador_inscricao_municipal',
            'opcao_simples_nacional',
            'regime_especial_tributacao',
            'regime_apuracao_simples_nacional',
            'emission_policy',
            'tributacao_issqn',
            'tipo_retencao_iss',
            'issqn_pais_resultado',
            'issqn_tipo_imunidade',
            'issqn_tipo_suspensao',
            'issqn_numero_processo_suspensao',
            'sandbox_mode',
        ] as $key) {
            if (array_key_exists($key, $fiscalInput)) {
                setting(['nfse.' . $key => $fiscalInput[$key]]);
            }
        }

        // Explicit operator evidence is bound to this CNPJ and municipality.
        // Changing either identity invalidates a previous confirmation,
        // including when the browser submits a still-checked checkbox.
        $confirmedCnpj = strtoupper(trim((string) ($fiscalInput['cnpj_prestador'] ?? '')));
        $confirmedMunicipality = trim((string) ($fiscalInput['municipio_ibge'] ?? ''));
        $identityChanged = ($previousCnpj !== '' && $previousCnpj !== $confirmedCnpj)
            || ($previousMunicipality !== '' && $previousMunicipality !== $confirmedMunicipality);
        $confirmed = filter_var($fiscalInput['provider_establishment_confirmed'] ?? false, FILTER_VALIDATE_BOOL)
            && !$identityChanged && $confirmedCnpj !== ''
            && preg_match('/^\d{7}$/D', $confirmedMunicipality) === 1;

        setting([
            'nfse.provider_establishment_confirmed' => $confirmed ? '1' : '0',
            'nfse.provider_establishment_confirmed_cnpj' => $confirmed ? $confirmedCnpj : '',
            'nfse.provider_establishment_confirmed_municipality' => $confirmed ? $confirmedMunicipality : '',
            'nfse.provider_establishment_confirmed_at' => $confirmed ? now()->toAtomString() : '',
            'nfse.provider_establishment_confirmed_by' => $confirmed ? (string) (auth()->id() ?? '') : '',
        ]);
        setting()->save();

        return redirect()->route('nfse.settings.edit', ['tab' => 'fiscal'])
            ->with('success', trans('nfse::general.saved'));
    }

    /**
     * Immutable, company-scoped revisions for approximate consumer tax disclosure.
     * These values are not assessed ISSQN/PIS/Cofins amounts.
     */
    public function approximateTaxes(): \Illuminate\View\View
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Company context required.');
        }

        return view('nfse::settings.approximate-taxes', [
            'sources' => ApproximateTaxSource::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function storeApproximateTaxes(Request $request): RedirectResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Company context required.');
        }

        $validated = $request->validate([
            'national_service_code' => ['required', 'regex:/^\d{6}$/D'],
            'simples_option' => 'required|in:1,2,3',
            'competence_from' => 'required|date_format:Y-m-d',
            'competence_until' => 'nullable|date_format:Y-m-d|after_or_equal:competence_from',
            'source_kind' => 'required|in:independent_estimate,taxpayer_declaration,documented_zero',
            'source_name' => 'required|string|min:3|max:160',
            'source_version' => 'required|string|min:1|max:80',
            'source_reference' => 'required|string|min:5|max:255',
            'federal_percent' => ['required', 'regex:/^(?:0|[1-9]\d{0,2})(?:[\.,]\d{1,2})?$/D'],
            'state_percent' => ['required', 'regex:/^(?:0|[1-9]\d{0,2})(?:[\.,]\d{1,2})?$/D'],
            'municipal_percent' => ['required', 'regex:/^(?:0|[1-9]\d{0,2})(?:[\.,]\d{1,2})?$/D'],
        ]);

        foreach (['federal_percent', 'state_percent', 'municipal_percent'] as $name) {
            $raw = str_replace(',', '.', (string) $validated[$name]);
            $parts = explode('.', $raw, 2);
            $validated[$name] = $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
        }

        if ($validated['source_kind'] === 'documented_zero'
            && ($validated['federal_percent'] !== '0.00'
                || $validated['state_percent'] !== '0.00'
                || $validated['municipal_percent'] !== '0.00')) {
            throw ValidationException::withMessages([
                'source_kind' => trans('nfse::general.settings.federal.approximate_zero_requires_zero'),
            ]);
        }

        // Append only. Earlier versions remain available to audit fiscal attempts.
        // Conflicting version identifiers in the same scope must be fixed explicitly.
        $scope = [
            'company_id' => $companyId,
            'national_service_code' => $validated['national_service_code'],
            'simples_option' => (int) $validated['simples_option'],
            'competence_from' => $validated['competence_from'],
            'source_version' => $validated['source_version'],
        ];

        if (ApproximateTaxSource::query()->where($scope)->exists()) {
            throw ValidationException::withMessages([
                'source_version' => trans('nfse::general.settings.federal.approximate_duplicate_version'),
            ]);
        }

        ApproximateTaxSource::query()->create([
            ...$validated,
            'company_id' => $companyId,
        ]);

        return redirect()->route('nfse.settings.approximate-taxes')
            ->with('success', trans('nfse::general.saved'));
    }

    public function updateFederal(Request $request): RedirectResponse
    {
        $settings = setting('nfse', []);
        $settingsArray = is_array($settings) ? $settings : [];

        if (!$this->isVaultReady($this->globalVaultReadinessSettings())) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', trans('nfse::general.vault_required_before_certificate_and_settings'));
        }

        $request->validate([
            'nfse.federal_piscofins_situacao_tributaria' => 'nullable|regex:/^\\d+$/',
            'nfse.federal_piscofins_tipo_retencao' => 'nullable|regex:/^\\d+$/',
            'nfse.ibs_cbs_enabled' => 'nullable|boolean',
            'nfse.ibs_cbs_ind_final' => 'nullable|in:0,1',
            'nfse.ibs_cbs_c_ind_op' => 'required_if:nfse.ibs_cbs_enabled,1|nullable|regex:/^\\d{6}$/',
            'nfse.ibs_cbs_ind_dest' => 'required_if:nfse.ibs_cbs_enabled,1|nullable|in:0,1',
            'nfse.ibs_cbs_cst' => 'required_if:nfse.ibs_cbs_enabled,1|nullable|regex:/^\\d{3}$/',
            'nfse.ibs_cbs_c_class_trib' => 'required_if:nfse.ibs_cbs_enabled,1|nullable|regex:/^\\d{6}$/',
        ]);

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $situacaoTributaria = trim((string) ($rawNfseInput['federal_piscofins_situacao_tributaria'] ?? ''));
        $tipoRetencao = trim((string) ($rawNfseInput['federal_piscofins_tipo_retencao'] ?? ''));
        $ibsCbsEnabled = $this->toBooleanInput($rawNfseInput, 'ibs_cbs_enabled', false);

        setting([
            'nfse.federal_piscofins_situacao_tributaria' => preg_match('/^\\d+$/', $situacaoTributaria) === 1 ? $situacaoTributaria : null,
            'nfse.federal_piscofins_tipo_retencao' => preg_match('/^\\d+$/', $tipoRetencao) === 1 ? $tipoRetencao : null,
            'nfse.ibs_cbs_enabled' => $ibsCbsEnabled,
            'nfse.ibs_cbs_ind_final' => $ibsCbsEnabled ? $this->nullableStringInput($rawNfseInput, 'ibs_cbs_ind_final') : null,
            'nfse.ibs_cbs_c_ind_op' => $ibsCbsEnabled ? $this->nullableStringInput($rawNfseInput, 'ibs_cbs_c_ind_op') : null,
            'nfse.ibs_cbs_ind_dest' => $ibsCbsEnabled ? $this->nullableStringInput($rawNfseInput, 'ibs_cbs_ind_dest') : null,
            'nfse.ibs_cbs_cst' => $ibsCbsEnabled ? $this->nullableStringInput($rawNfseInput, 'ibs_cbs_cst') : null,
            'nfse.ibs_cbs_c_class_trib' => $ibsCbsEnabled ? $this->nullableStringInput($rawNfseInput, 'ibs_cbs_c_class_trib') : null,
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

    /**
     * @param array<string, mixed> $input
     */
    protected function nullableStringInput(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }

        $value = trim((string) $input[$key]);

        return $value !== '' ? $value : null;
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

    public function nationalServices(Request $request, OfficialDomainCatalog $catalog): JsonResponse
    {
        $rawLc = $request->query('lc116', '');
        $rawQuery = $request->query('q', '');
        $lc = is_string($rawLc) ? Lc116Code::normalize($rawLc) : '';
        $query = is_string($rawQuery) ? trim($rawQuery) : '';

        if ($lc !== '' && preg_match('/^\\d{4}$/', $lc) !== 1) {
            return $this->jsonResponse(['data' => []], 422);
        }

        if (mb_strlen($query) > 100) {
            return $this->jsonResponse(['data' => []], 422);
        }

        // The official catalog API applies a maximum result cap; querying by LC
        // prefix first preserves related codes before additional text filtering.
        if ($lc === '' && mb_strlen($query) < 2) {
            return $this->jsonResponse(['data' => []]);
        }

        $entries = $catalog->searchNationalServices($lc !== '' ? $lc : $query, 500);
        if ($lc !== '') {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => str_starts_with($entry['code'], $lc)
            ));
        }
        if ($query !== '' && $lc !== '') {
            $term = mb_strtolower($query, 'UTF-8');
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool =>
                    str_contains(mb_strtolower($entry['code'] . ' ' . $entry['description'], 'UTF-8'), $term)
            ));
        }

        return $this->jsonResponse(['data' => array_slice($entries, 0, 50)]);
    }

    public function municipalParameters(Request $request): JsonResponse
    {
        $companyId = $this->municipalCompanyId();
        if ($companyId <= 0) {
            return $this->jsonResponse(['message' => 'Municipal company context is unavailable.'], 422);
        }

        try {
            $fiscal = $this->municipalFiscalSettings($companyId);
        } catch (\DomainException) {
            return $this->jsonResponse(['message' => 'NFS-e company profile is not configured.'], 422);
        }

        $municipio = trim((string) $request->query('municipio_ibge', $fiscal['municipio_ibge'] ?? ''));
        $serviceCode = preg_replace('/[^0-9]/', '', (string) $request->query('service_code', '')) ?? '';
        $competence = trim((string) $request->query('competence', date('Y-m-d')));

        if (preg_match('/^\\d{7}$/', $municipio) !== 1) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.settings.municipal_parameters.invalid_municipality'),
            ], 422);
        }

        if (preg_match('/^\\d{9}$/', $serviceCode) !== 1) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.settings.municipal_parameters.invalid_service'),
            ], 422);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competence);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $competence) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.settings.municipal_parameters.invalid_competence'),
            ], 422);
        }

        $environment = filter_var($fiscal['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOL) ? 'sandbox' : 'production';

        try {
            $resolved = (new MunicipalParameterSnapshotStore())->resolve(
                companyId: $companyId,
                environment: $environment,
                municipioIbge: $municipio,
                serviceCode: $serviceCode,
                competence: $competence,
                fetch: fn (): array|MunicipalParameterConsultation => $this->fetchMunicipalParameters($municipio, $serviceCode, $competence),
            );

            return $this->jsonResponse($resolved);
        } catch (QueryException $e) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.settings.municipal_parameters.query_failed'),
                'upstream' => [
                    'http_status' => $e->httpStatus,
                    'payload' => $e->upstreamPayload,
                ],
            ], 502);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.settings.municipal_parameters.query_failed'),
                'detail' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * @return array<string,mixed>|MunicipalParameterConsultation
     */
    protected function fetchMunicipalParameters(string $municipio, string $serviceCode, string $competence): array|MunicipalParameterConsultation
    {
        $companyId = $this->municipalCompanyId();
        $fiscal = $this->municipalFiscalSettings($companyId);
        $cnpj = trim((string) ($fiscal['cnpj_prestador'] ?? ''));
        if ($cnpj === '') {
            throw new \RuntimeException('Service provider CNPJ is not configured.');
        }

        $sandbox = filter_var($fiscal['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOL);
        $context = $this->makeFiscalClientFactory()->municipalParameters($sandbox, $companyId);

        try {
            $client = $context->municipalParametersClient();
            $convenioStatus = null;
            $aliquotaStatus = null;
            $regimesStatus = null;
            $retencoesStatus = null;

            // Keep the existing public JSON data contract unchanged. Status
            // and API provenance are returned separately to the snapshot store.
            $data = [
                'municipio_ibge' => $municipio,
                'service_code' => $serviceCode,
                'competence' => $competence,
                'convenio' => $this->municipalEndpointResult(
                    fn (): array => $client->convenio($municipio),
                    $convenioStatus,
                ),
                'aliquota' => $this->municipalEndpointResult(
                    fn (): array => $client->aliquota($municipio, $serviceCode, $competence),
                    $aliquotaStatus,
                ),
                'regimes_especiais' => $this->municipalEndpointResult(
                    fn (): array => $client->regimesEspeciais($municipio, $serviceCode, $competence),
                    $regimesStatus,
                ),
                'retencoes' => $this->municipalEndpointResult(
                    fn (): array => $client->retencoes($municipio, $competence),
                    $retencoesStatus,
                ),
            ];

            $baseUrl = (new MunicipalParametersConfig(sandboxMode: $sandbox))->baseUrl;
            $servicePath = sprintf(
                '%s.%s.%s.%s',
                substr($serviceCode, 0, 2),
                substr($serviceCode, 2, 2),
                substr($serviceCode, 4, 2),
                substr($serviceCode, 6, 3),
            );
            $root = $baseUrl . '/' . $municipio;

            $endpoints = [];
            foreach ([
                'convenio' => [$root . '/convenio', $convenioStatus],
                'aliquota' => [$root . '/' . $servicePath . '/' . $competence . '/aliquota', $aliquotaStatus],
                'regimes_especiais' => [$root . '/' . $servicePath . '/' . $competence . '/regimes_especiais', $regimesStatus],
                'retencoes' => [$root . '/' . $competence . '/retencoes', $retencoesStatus],
            ] as $name => [$url, $status]) {
                $endpoints[$name] = [
                    'source_url' => $url,
                    // nfse-php returns only a decoded body on success; its
                    // exact HTTP status cannot be recovered. Do not invent 200.
                    'http_status' => $status,
                    'outcome' => $status === 404 ? 'http_404' : 'decoded_response',
                ];
            }

            return new MunicipalParameterConsultation($data, [
                'endpoints' => $endpoints,
                // The endpoint does not expose a reliable schema version or
                // validity period in the currently consumed SDK responses.
                'contract_version' => null,
                'valid_from' => null,
                'valid_until' => null,
            ]);
        } finally {
            $context->close();
        }
    }

    /**
     * A municipal GET returning HTTP 404 is inconclusive, not a refusal.
     * Other upstream failures still fail the aggregate query, enabling only
     * an exact-context stale-cache fallback.
     *
     * @param callable(): array<string,mixed> $query
     * @return array<string,mixed>
     */
    protected function municipalEndpointResult(callable $query, ?int &$httpStatus = null): array
    {
        try {
            return $query();
        } catch (QueryException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }

            $httpStatus = 404;

            return $e->upstreamPayload;
        }
    }

    /** @return array<string,mixed> */
    protected function municipalFiscalSettings(int $companyId): array
    {
        return (new NfseCompanyFiscalSettings())->all($companyId);
    }

    protected function municipalCompanyId(): int
    {
        return function_exists('company_id') ? (int) company_id() : 0;
    }

    protected function makeFiscalClientFactory(): FiscalClientFactory
    {
        return app(FiscalClientFactory::class);
    }

    protected function sandboxModeEnabled(): bool
    {
        return $this->toBooleanInput([
            'sandbox_mode' => setting('nfse.sandbox_mode', true),
        ], 'sandbox_mode', true);
    }

    public function update(Request $request): RedirectResponse
    {
        $previousCnpj = (string) setting('nfse.cnpj_prestador', '');
        $previousMunicipality = (string) setting('nfse.municipio_ibge', '');

        $rawNfseInput = $request->input('nfse', []);
        $rawNfseInput = is_array($rawNfseInput) ? $rawNfseInput : [];
        $nfseInput = $this->prepareNfseInput($rawNfseInput);

        // An establishment attestation must come from the authenticated
        // fiscal settings action, which records its actor and fiscal identity.
        // A legacy configuration submission cannot forge the evidence.
        foreach ([
            'provider_establishment_confirmed',
            'provider_establishment_confirmed_cnpj',
            'provider_establishment_confirmed_municipality',
            'provider_establishment_confirmed_at',
            'provider_establishment_confirmed_by',
        ] as $attestationKey) {
            unset($nfseInput[$attestationKey]);
        }

        $existingVaultSettings = $this->globalVaultReadinessSettings();

        // The legacy endpoint must not mutate global infrastructure or use
        // company-scoped inputs as a bypass of global capability policy.
        foreach (['bao_addr', 'bao_mount', 'bao_token', 'bao_role_id', 'bao_secret_id', 'clear_bao_token', 'clear_bao_secret_id'] as $key) {
            unset($nfseInput[$key]);
        }
        $projectedVaultSettings = $existingVaultSettings;
        $vaultReadyAfterSubmission = $this->isVaultReady($projectedVaultSettings);

        $certificatePayload = null;
        $certificateFile = $request->file('pfx_file');
        $isReplacingCertificate = $certificateFile instanceof UploadedFile;
        $isVaultOnlySubmission = !$isReplacingCertificate && !array_key_exists('cnpj_prestador', $rawNfseInput);

        if ($isVaultOnlySubmission) {
            return redirect()->route('nfse.settings.edit', ['tab' => 'vault'])
                ->with('error', 'Use the global Vault settings form.');
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
            'nfse.sandbox_mode'    => 'nullable|boolean',
            'nfse.regime_especial_tributacao' => 'nullable|in:0,1,2,3,4,5,6,9',
            'nfse.regime_apuracao_simples_nacional' => 'nullable|in:1,2,3',
            'replace_certificate'  => 'nullable|boolean',
            'pfx_file'             => 'nullable|file|extensions:pfx,p12|max:1024',
            'pfx_password'         => 'nullable|string|max:255|required_with:pfx_file',
        ]);

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

        $regime = $this->specialRegimeAssessment($nfseInput, $isReplacingCertificate ? [] : (
            is_array(setting('nfse', [])) ? setting('nfse', []) : []
        ));
        if ($regime['state'] === 'invalid') {
            return redirect()->back()->withInput()->with('error', $this->specialRegimeError($regime['reason']));
        }
        if (array_key_exists('regime_especial_tributacao', $nfseInput)) {
            $nfseInput['regime_especial_tributacao'] = $regime['code'] !== null
                ? (string) $regime['code']
                : null;
        }

        if ($isReplacingCertificate && $previousCnpj !== '') {
            $this->purgeCertificateArtifacts($previousCnpj);
            $this->clearNfseSettings();
        }

        foreach ($nfseInput as $key => $value) {
            setting(['nfse.' . $key => $value]);
        }

        // The legacy route can change the CNPJ or municipality. Clear prior
        // attestation irreversibly, so reverting the identity later cannot
        // resurrect stale establishment evidence.
        if ($isReplacingCertificate
            || $previousCnpj !== (string) ($nfseInput['cnpj_prestador'] ?? '')
            || $previousMunicipality !== (string) ($nfseInput['municipio_ibge'] ?? '')) {
            setting([
                'nfse.provider_establishment_confirmed' => '0',
                'nfse.provider_establishment_confirmed_cnpj' => '',
                'nfse.provider_establishment_confirmed_municipality' => '',
                'nfse.provider_establishment_confirmed_at' => '',
                'nfse.provider_establishment_confirmed_by' => '',
            ]);
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
     * Resolve the company-scoped fiscal selection without writing settings.
     * An omitted input retains the previous selection; an explicit blank
     * clears it, and neither state is silently normalized to code 0.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $settings
     * @return array{code:?int,state:'missing'|'invalid'|'unsupported'|'none'|'special',can_emit:bool,reason:string,municipal_review_required:bool}
     */
    private function specialRegimeAssessment(array $input, array $settings): array
    {
        $code = array_key_exists('regime_especial_tributacao', $input)
            ? $input['regime_especial_tributacao']
            : ($settings['regime_especial_tributacao'] ?? null);
        $rawSimples = array_key_exists('opcao_simples_nacional', $input)
            ? $input['opcao_simples_nacional']
            : ($settings['opcao_simples_nacional'] ?? null);
        $simples = SimplesOptionCode::observed($rawSimples) ?? 0;
        $issqn = (int) ($input['tributacao_issqn'] ?? $settings['tributacao_issqn'] ?? 1);
        $apuration = array_key_exists('regime_apuracao_simples_nacional', $input)
            ? $input['regime_apuracao_simples_nacional']
            : ($settings['regime_apuracao_simples_nacional'] ?? null);

        return (new SpecialTaxRegimePolicy())->evaluate($code, $simples, $issqn, null, $apuration);
    }

    private function specialRegimeError(string $reason): string
    {
        return trans('nfse::general.settings.special_regime_error.' . $reason);
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
        $nfseInput['item_lista_servico'] = Lc116Code::normalize($nfseInput['item_lista_servico'] ?? '');
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
     * Global Vault metadata only. Certificate readiness belongs to the
     * company profiles and must not be inferred from a legacy CNPJ.
     *
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $globalVault
     * @return array{auth_mode:string,ready:bool}
     */
    protected function vaultUiState(array $globalVault): array
    {
        $token = trim((string) ($globalVault['token'] ?? '')) !== '';
        $approle = trim((string) ($globalVault['roleId'] ?? '')) !== ''
            && trim((string) ($globalVault['secretId'] ?? '')) !== '';

        return [
            'auth_mode' => $approle ? 'approle' : ($token ? 'token' : 'incomplete'),
            'ready' => trim($globalVault['addr']) !== ''
                && trim($globalVault['mount']) !== ''
                && ($token || $approle),
        ];
    }

    /**
     * @param array<string, string> $currentVaultSettings
     * @param array<string, mixed> $nfseInput
     * @return array<string, string>
     */

    /**
     * Application-wide infrastructure only. This protected boundary enables
     * deterministic pure-PHP controller tests without a database facade.
     *
     * @return array{bao_addr:string,bao_mount:string,bao_token:string,bao_role_id:string,bao_secret_id:string}
     */
    protected function globalVaultReadinessSettings(): array
    {
        $resolved = VaultConfig::secretStoreConfig();

        return [
            'bao_addr' => $resolved['addr'],
            'bao_mount' => $resolved['mount'],
            'bao_token' => $resolved['token'] ?? '',
            'bao_role_id' => $resolved['roleId'] ?? '',
            'bao_secret_id' => $resolved['secretId'] ?? '',
        ];
    }

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

    protected function makeSecretStore(): SecretStoreInterface
    {
        return app(SecretStoreInterface::class);
    }
}
