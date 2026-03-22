{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.title') }}</x-slot>

    <x-slot name="content">
        <div class="max-w-4xl">
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    {{ session('error') }}
                </div>
            @endif

            <form id="settings-form" method="POST" action="{{ route('nfse.settings.update') }}" enctype="multipart/form-data" class="space-y-8">
                @csrf
                @method('PATCH')

                {{-- ─────────────────────────────────────────────────────── --}}
                {{-- Step 1 · Digital certificate (single action)          --}}
                {{-- ─────────────────────────────────────────────────────── --}}
                <div class="bg-white rounded-lg shadow p-6 space-y-4">
                    <h3 class="text-xl font-semibold">{{ trans('nfse::general.step_certificate') }}</h3>
                    <p class="text-sm text-gray-500">{{ trans('nfse::general.settings.certificate_hint') }}</p>
                    <input type="hidden" id="replace_certificate" name="replace_certificate" value="{{ ($certificateState['has_saved_settings'] ?? false) ? '0' : '1' }}">

                    @if(($certificateState['has_saved_settings'] ?? false) === true)
                        <div class="p-3 rounded border border-blue-300 bg-blue-50 text-blue-800 text-sm space-y-1">
                            <p class="font-semibold">{{ trans('nfse::general.saved_state_title') }}</p>
                            <p>{{ trans('nfse::general.saved_state_cnpj') }} <span class="font-mono">{{ $certificateState['cnpj'] }}</span></p>
                            <p>{{ trans('nfse::general.saved_state_city') }} {{ setting('nfse.municipio_nome', '-') }} ({{ setting('nfse.uf', '-') }})</p>
                            <p>{{ trans('nfse::general.saved_state_iss') }} {{ setting('nfse.aliquota', '-') }}%</p>
                            <p>
                                {{ trans('nfse::general.saved_state_certificate') }}
                                @if(($certificateState['has_local_certificate'] ?? false) === true)
                                    {{ trans('nfse::general.saved_state_certificate_present') }}
                                @else
                                    {{ trans('nfse::general.saved_state_certificate_missing') }}
                                @endif
                            </p>
                            <div class="pt-2 flex flex-wrap gap-2">
                                <button type="button" id="btn-show-replace-cert" class="inline-flex items-center px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700">
                                    {{ trans('nfse::general.replace_certificate') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    <div id="replace-cert-fields" @if(($certificateState['has_saved_settings'] ?? false) === true) hidden @endif class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="pfx_file">{{ trans('nfse::general.settings.certificate') }}</label>
                        <input id="pfx_file" name="pfx_file" type="file" accept=".pfx,.p12" class="w-full border rounded px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="pfx_password">{{ trans('nfse::general.settings.pfx_password') }}</label>
                        <input id="pfx_password" name="pfx_password" type="password" class="w-full border rounded px-3 py-2" autocomplete="new-password">
                    </div>

                    <p class="text-xs text-gray-500">{{ trans('nfse::general.settings.edit_hint_without_certificate') }}</p>
                    <div>
                        <a href="{{ route('nfse.settings.readiness') }}" class="inline-flex items-center px-3 py-2 rounded bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs">
                            {{ trans('nfse::general.go_to_readiness') }}
                        </a>
                    </div>

                    {{-- CNPJ badge shown after a successful parse --}}
                    <div id="cert-cnpj-display" class="hidden flex items-center gap-2 p-3 bg-green-50 border border-green-300 rounded">
                        <span class="text-sm text-green-700">{{ trans('nfse::general.cnpj_from_certificate') }}</span>
                        <span id="cert-cnpj-value" class="font-mono font-bold text-green-900"></span>
                    </div>

                    <div id="cert-error-display" class="hidden text-red-600 text-sm"></div>

                    <div class="flex flex-wrap gap-3">
                        <button type="button" id="btn-read-cert" class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            {{ trans('nfse::general.read_certificate') }}
                        </button>
                    </div>

                    @if(($certificateState['has_saved_settings'] ?? false) === true)
                    <div class="border-t border-gray-200 pt-3">
                        <button type="button" id="btn-delete-certificate" class="inline-flex items-center px-3 py-1.5 rounded bg-red-600 text-white hover:bg-red-700 text-sm">
                            {{ trans('nfse::general.delete_certificate_and_settings') }}
                        </button>
                    </div>
                    @endif
                    </div>
                </div>

                {{-- ─────────────────────────────────────────────────────── --}}
                {{-- Step 2 · NFS-e settings (shown after CNPJ read)      --}}
                {{-- ─────────────────────────────────────────────────────── --}}
                <div id="step-settings-section" class="space-y-8" @if(($certificateState['has_saved_settings'] ?? false) !== true) hidden @endif>
                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
                    <h3 class="text-xl font-semibold">{{ trans('nfse::general.step_settings') }}</h3>

                    {{-- CNPJ: populated by "Ler certificado" or falls back to saved value --}}
                    <div>
                        <label class="block text-sm font-medium mb-1" for="cnpj_prestador">{{ trans('nfse::general.settings.cnpj_from_certificate') }}</label>
                        <input id="cnpj_prestador" name="nfse[cnpj_prestador]" type="text" class="w-full border rounded px-3 py-2 bg-gray-50 text-gray-500" value="{{ old('nfse.cnpj_prestador', setting('nfse.cnpj_prestador')) }}" readonly>
                        <p class="text-xs text-gray-400 mt-1">{{ trans('nfse::general.cnpj_from_certificate') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="uf">{{ trans('nfse::general.settings.uf') }}</label>
                        <select id="uf" name="nfse[uf]" class="w-full border rounded px-3 py-2" required>
                            <option value="">Selecione...</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="municipio_nome">{{ trans('nfse::general.settings.municipio_nome') }}</label>
                        <select id="municipio_nome" name="nfse[municipio_nome]" class="w-full border rounded px-3 py-2" required disabled>
                            <option value="">Selecione o estado primeiro...</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="municipio_ibge_display">{{ trans('nfse::general.settings.municipio_ibge') }}</label>
                        <input id="municipio_ibge_display" type="text" class="w-full border rounded px-3 py-2 bg-gray-50" value="{{ old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '')) }}" readonly>
                        <input id="municipio_ibge" name="nfse[municipio_ibge]" type="hidden" value="{{ old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '')) }}" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="item_lista_servico">{{ trans('nfse::general.settings.item_lista') }}</label>
                        <input id="item_lista_servico_display" name="nfse[item_lista_servico_display]" type="text" list="lc116_services" class="w-full border rounded px-3 py-2" value="" placeholder="{{ trans('nfse::general.settings.item_lista_hint') }}" autocomplete="off" required>
                        <datalist id="lc116_services"></datalist>
                        <input id="item_lista_servico" name="nfse[item_lista_servico]" type="hidden" value="{{ old('nfse.item_lista_servico', setting('nfse.item_lista_servico', '0107')) }}" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="aliquota">{{ trans('nfse::general.settings.aliquota') }}</label>
                        <input id="aliquota" name="nfse[aliquota]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.aliquota', setting('nfse.aliquota', '5.00')) }}">
                    </div>

                    <label class="inline-flex items-center gap-2">
                        <input name="nfse[sandbox_mode]" type="checkbox" value="1" @checked((bool) old('nfse.sandbox_mode', setting('nfse.sandbox_mode', true)))>
                        <span>{{ trans('nfse::general.settings.sandbox_mode') }}</span>
                    </label>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
                    <h3 class="text-xl font-semibold">OpenBao / Vault</h3>

                    <div class="p-3 rounded border border-blue-300 bg-blue-50 text-blue-800 text-sm space-y-2">
                        <p class="font-semibold">{{ trans('nfse::general.settings.vault_status_title') }}</p>
                        <p id="vault-status-addr" data-configured="{{ ($vaultUiState['addr_configured'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_addr') }}: {{ ($vaultUiState['addr_configured'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                        <p id="vault-status-mount" data-configured="{{ ($vaultUiState['mount_configured'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_mount') }}: {{ ($vaultUiState['mount_configured'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                        <p id="vault-status-token" data-configured="{{ ($vaultUiState['token_configured'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_token') }}: {{ ($vaultUiState['token_configured'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                        <p id="vault-status-role-id" data-configured="{{ ($vaultUiState['role_id_configured'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_role_id') }}: {{ ($vaultUiState['role_id_configured'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                        <p id="vault-status-secret-id" data-configured="{{ ($vaultUiState['secret_id_configured'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_secret_id') }}: {{ ($vaultUiState['secret_id_configured'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                        <p id="vault-status-auth-mode" data-mode="{{ (string) ($vaultUiState['auth_mode'] ?? 'incomplete') }}">
                            {{ trans('nfse::general.settings.vault_status_auth_mode') }}: {{ trans('nfse::general.settings.vault_auth_mode_' . (string) ($vaultUiState['auth_mode'] ?? 'incomplete')) }}
                        </p>
                        <p id="vault-status-certificate-secret" data-configured="{{ ($vaultUiState['certificate_secret_available'] ?? false) ? '1' : '0' }}">
                            {{ trans('nfse::general.settings.vault_status_certificate_secret') }}: {{ ($vaultUiState['certificate_secret_available'] ?? false) ? trans('general.yes') : trans('general.no') }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="bao_addr">{{ trans('nfse::general.settings.bao_addr') }}</label>
                        <input id="bao_addr" name="nfse[bao_addr]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_addr', setting('nfse.bao_addr', 'http://openbao:8200')) }}" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="bao_mount">{{ trans('nfse::general.settings.bao_mount') }}</label>
                        <input id="bao_mount" name="nfse[bao_mount]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_mount', setting('nfse.bao_mount', 'nfse')) }}">
                        <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_mount_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="bao_token">{{ trans('nfse::general.settings.bao_token') }}</label>
                        <input id="bao_token" name="nfse[bao_token]" type="password" class="w-full border rounded px-3 py-2" autocomplete="new-password">
                        <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_token_hint') }}</p>
                        <label class="inline-flex items-center gap-2 mt-2 text-xs text-gray-700">
                            <input id="clear_bao_token" name="nfse[clear_bao_token]" type="checkbox" value="1" @checked((string) old('nfse.clear_bao_token', '0') === '1')>
                            <span>{{ trans('nfse::general.settings.clear_bao_token') }}</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="bao_role_id">{{ trans('nfse::general.settings.bao_role_id') }}</label>
                        <input id="bao_role_id" name="nfse[bao_role_id]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_role_id', setting('nfse.bao_role_id')) }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="bao_secret_id">{{ trans('nfse::general.settings.bao_secret_id') }}</label>
                        <input id="bao_secret_id" name="nfse[bao_secret_id]" type="password" class="w-full border rounded px-3 py-2" autocomplete="new-password">
                        <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_secret_id_hint') }}</p>
                        <label class="inline-flex items-center gap-2 mt-2 text-xs text-gray-700">
                            <input id="clear_bao_secret_id" name="nfse[clear_bao_secret_id]" type="checkbox" value="1" @checked((string) old('nfse.clear_bao_secret_id', '0') === '1')>
                            <span>{{ trans('nfse::general.settings.clear_bao_secret_id') }}</span>
                        </label>
                    </div>

                    <p class="text-xs text-gray-500">{{ trans('nfse::general.settings.sensitive_fields_behavior_hint') }}</p>
                    </div>

                    <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">
                        {{ trans('general.save') }}
                    </button>
                </div>
            </form>

            <form id="delete-certificate-form" method="POST" action="{{ route('nfse.certificate.destroy') }}" class="mt-4" @if(($certificateState['has_saved_settings'] ?? false) === true) hidden @endif>
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700" onclick="return confirm('{{ trans('nfse::general.confirm_delete_certificate_and_settings') }}');">
                    {{ trans('nfse::general.delete_certificate_and_settings') }}
                </button>
            </form>

        </div>

        <script>
            document.addEventListener('DOMContentLoaded', async () => {
                const ufSelect = document.getElementById('uf');
                const municipalitySelect = document.getElementById('municipio_nome');
                const ibgeHidden = document.getElementById('municipio_ibge');
                const ibgeDisplay = document.getElementById('municipio_ibge_display');
                const cnpjInput = document.getElementById('cnpj_prestador');

                const selectedUf = @json(old('nfse.uf', setting('nfse.uf', '')));
                const selectedMunicipalityName = @json(old('nfse.municipio_nome', setting('nfse.municipio_nome', '')));
                const selectedIbge = @json(old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '')));
                const selectedLc116Code = @json(old('nfse.item_lista_servico', setting('nfse.item_lista_servico', '0107')));

                const ufsUrl = @json(route('nfse.ibge.ufs'));
                const municipalitiesUrlTemplate = @json(route('nfse.ibge.municipalities', ['uf' => '__UF__']));
                const lc116ServicesUrl = @json(route('nfse.lc116.services'));
                const parsePfxUrl = @json(route('nfse.certificate.parse'));

                const lc116DisplayInput = document.getElementById('item_lista_servico_display');
                const lc116CodeInput = document.getElementById('item_lista_servico');
                const lc116Datalist = document.getElementById('lc116_services');
                const lc116ByLabel = new Map();
                const lc116ByCode = new Map();

                const fetchJson = async (url) => {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const payload = await response.json().catch(() => ({ data: [] }));

                    if (!response.ok) {
                        return [];
                    }

                    return Array.isArray(payload.data) ? payload.data : [];
                };

                const renderLc116Options = (services) => {
                    lc116Datalist.innerHTML = '';

                    services.forEach((service) => {
                        const option = document.createElement('option');
                        option.value = service.label;
                        lc116Datalist.appendChild(option);

                        lc116ByLabel.set(service.label, service.code);
                        lc116ByCode.set(service.code, service.label);
                    });
                };

                const renderMunicipalities = (municipalities, selectedName, selectedCode) => {
                    municipalitySelect.innerHTML = '';

                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'Selecione...';
                    municipalitySelect.appendChild(placeholder);

                    municipalities.forEach((city) => {
                        const option = document.createElement('option');
                        option.value = city.name;
                        option.textContent = city.name;
                        option.dataset.ibge = city.ibge_code;

                        if ((selectedCode && city.ibge_code === selectedCode) || (!selectedCode && selectedName && city.name === selectedName)) {
                            option.selected = true;
                            ibgeHidden.value = city.ibge_code;
                            ibgeDisplay.value = city.ibge_code;
                        }

                        municipalitySelect.appendChild(option);
                    });

                    municipalitySelect.disabled = false;
                };

                const loadMunicipalities = async (uf, preferredName = '', preferredCode = '') => {
                    if (!uf) {
                        municipalitySelect.disabled = true;
                        municipalitySelect.innerHTML = '<option value="">Selecione o estado primeiro...</option>';
                        ibgeHidden.value = '';
                        ibgeDisplay.value = '';
                        return;
                    }

                    municipalitySelect.disabled = true;
                    municipalitySelect.innerHTML = '<option value="">Carregando municípios...</option>';

                    const url = municipalitiesUrlTemplate.replace('__UF__', encodeURIComponent(uf));
                    const municipalities = await fetchJson(url);
                    renderMunicipalities(municipalities, preferredName, preferredCode);
                };

                const ufsPromise = fetchJson(ufsUrl);
                const lc116ServicesPromise = fetchJson(lc116ServicesUrl);

                ufSelect.addEventListener('change', async () => {
                    await loadMunicipalities(ufSelect.value);
                });

                municipalitySelect.addEventListener('change', () => {
                    const selectedOption = municipalitySelect.options[municipalitySelect.selectedIndex];
                    const ibgeCode = selectedOption?.dataset?.ibge ?? '';
                    ibgeHidden.value = ibgeCode;
                    ibgeDisplay.value = ibgeCode;
                });

                lc116DisplayInput.addEventListener('input', () => {
                    const normalizedCode = lc116ByLabel.get(lc116DisplayInput.value) ?? '';
                    lc116CodeInput.value = normalizedCode;
                });

                // ── Certificate wizard ──────────────────────────────────────
                const btnReadCert = document.getElementById('btn-read-cert');
                const pfxFileInput = document.getElementById('pfx_file');
                const pfxPasswordInput = document.getElementById('pfx_password');
                const certCnpjDisplay = document.getElementById('cert-cnpj-display');
                const certCnpjValue = document.getElementById('cert-cnpj-value');
                const certErrorDisplay = document.getElementById('cert-error-display');
                const stepSettingsSection = document.getElementById('step-settings-section');
                const replaceCertificateInput = document.getElementById('replace_certificate');
                const replaceFields = document.getElementById('replace-cert-fields');
                const showReplaceButton = document.getElementById('btn-show-replace-cert');
                const deleteCertificateButton = document.getElementById('btn-delete-certificate');
                const deleteForm = document.getElementById('delete-certificate-form');
                const hasSavedSettings = @json(($certificateState['has_saved_settings'] ?? false) === true);

                const settingsForm = document.getElementById('settings-form');
                const csrfToken = settingsForm?.querySelector('input[name="_token"]')?.value ?? '';

                const toggleStepSettings = (isVisible) => {
                    if (stepSettingsSection) {
                        stepSettingsSection.hidden = !isVisible;
                    }
                };

                const toggleReplaceFields = (isVisible) => {
                    if (replaceFields) {
                        replaceFields.hidden = !isVisible;
                    }

                    if (replaceCertificateInput) {
                        replaceCertificateInput.value = isVisible ? '1' : '0';
                    }
                };

                const syncReadButtonState = () => {
                    if (!btnReadCert || !pfxPasswordInput) {
                        return;
                    }

                    btnReadCert.disabled = pfxPasswordInput.value.trim().length === 0;
                };

                toggleStepSettings(hasSavedSettings);

                if (!hasSavedSettings) {
                    toggleReplaceFields(true);
                }

                syncReadButtonState();
                pfxPasswordInput?.addEventListener('input', syncReadButtonState);

                showReplaceButton?.addEventListener('click', () => {
                    toggleReplaceFields(true);
                    syncReadButtonState();
                    pfxFileInput.focus();
                });

                (async () => {
                    const [ufs, lc116Services] = await Promise.all([ufsPromise, lc116ServicesPromise]);
                    renderLc116Options(lc116Services);

                    const initialLc116Label = lc116ByCode.get(selectedLc116Code);
                    if (initialLc116Label) {
                        lc116DisplayInput.value = initialLc116Label;
                        lc116CodeInput.value = selectedLc116Code;
                    }

                    ufSelect.innerHTML = '';

                    const ufPlaceholder = document.createElement('option');
                    ufPlaceholder.value = '';
                    ufPlaceholder.textContent = 'Selecione...';
                    ufSelect.appendChild(ufPlaceholder);

                    ufs.forEach((entry) => {
                        const option = document.createElement('option');
                        option.value = entry.uf;
                        option.textContent = `${entry.uf} - ${entry.name}`;
                        if (entry.uf === selectedUf) {
                            option.selected = true;
                        }
                        ufSelect.appendChild(option);
                    });

                    if (selectedUf) {
                        await loadMunicipalities(selectedUf, selectedMunicipalityName, selectedIbge);
                    }
                })();

                deleteCertificateButton?.addEventListener('click', () => {
                    if (!confirm(@json(trans('nfse::general.confirm_delete_certificate_and_settings')))) {
                        return;
                    }

                    deleteForm?.submit();
                });

                btnReadCert.addEventListener('click', async () => {
                    certErrorDisplay.classList.add('hidden');
                    certCnpjDisplay.classList.add('hidden');

                    if (!pfxFileInput.files.length) {
                        certErrorDisplay.textContent = @json(trans('nfse::general.settings.certificate')) + ': selecione um arquivo PFX.';
                        certErrorDisplay.classList.remove('hidden');
                        return;
                    }

                    btnReadCert.disabled = true;

                    const formData = new FormData();
                    formData.append('pfx_file', pfxFileInput.files[0]);
                    formData.append('pfx_password', pfxPasswordInput.value);
                    formData.append('_token', csrfToken);

                    try {
                        const response = await fetch(parsePfxUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: formData,
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            certErrorDisplay.textContent = payload.error ?? @json(trans('nfse::general.invalid_pfx'));
                            certErrorDisplay.classList.remove('hidden');
                            return;
                        }

                        const cnpj = payload.data?.cnpj ?? null;

                        if (!cnpj) {
                            certErrorDisplay.textContent = @json(trans('nfse::general.cnpj_not_found'));
                            certErrorDisplay.classList.remove('hidden');
                            return;
                        }

                        certCnpjValue.textContent = cnpj;
                        certCnpjDisplay.classList.remove('hidden');
                        cnpjInput.value = cnpj;
                        toggleStepSettings(true);
                    } catch {
                        certErrorDisplay.textContent = @json(trans('nfse::general.invalid_pfx'));
                        certErrorDisplay.classList.remove('hidden');
                    } finally {
                        btnReadCert.disabled = false;
                    }
                });
            });
        </script>
    </x-slot>
</x-layouts.admin>
