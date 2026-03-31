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

            @php
                $vaultReady = ($vaultUiState['ready'] ?? false) === true;
                $hasSavedSettings = ($certificateState['has_saved_settings'] ?? false) === true;
                $selectedAuthMode = old('auth_mode_ui', (string) ($vaultUiState['auth_mode'] ?? 'incomplete'));
                if (! in_array($selectedAuthMode, ['token', 'approle'], true)) {
                    $selectedAuthMode = 'token';
                }
                $tabs = [
                    'vault'       => ['label' => trans('nfse::general.settings.vault_section_title'), 'enabled' => true],
                    'certificate' => ['label' => trans('nfse::general.step_certificate'),             'enabled' => $vaultReady],
                    'fiscal'      => ['label' => trans('nfse::general.step_settings'),                'enabled' => $hasSavedSettings],
                    'services'    => ['label' => trans('nfse::general.settings.services.tab_title'), 'enabled' => $hasSavedSettings],
                    'federal'     => ['label' => trans('nfse::general.settings.federal.tab_title'),  'enabled' => $hasSavedSettings],
                ];

                $selectedFederalMode = old('nfse.tributacao_federal_mode', setting('nfse.tributacao_federal_mode', 'per_invoice_amounts'));
                if (! in_array($selectedFederalMode, ['per_invoice_amounts', 'percentage_profile'], true)) {
                    $selectedFederalMode = 'per_invoice_amounts';
                }
            @endphp

            {{-- ── Tab navigation ──────────────────────────────────────── --}}
            <div class="border-b border-gray-200 mb-6">
                <nav class="-mb-px flex" aria-label="{{ trans('nfse::general.settings.title') }}">
                    @foreach($tabs as $tabKey => $tab)
                        <button
                            type="button"
                            data-tab="{{ $tabKey }}"
                            id="tab-btn-{{ $tabKey }}"
                            class="tab-button px-5 py-3 text-sm font-medium border-b-2 whitespace-nowrap {{ $activeTab === $tabKey ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} {{ !$tab['enabled'] ? 'opacity-40 cursor-not-allowed' : '' }}"
                            @if(!$tab['enabled']) disabled aria-disabled="true" @endif
                        >{{ $tab['label'] }}</button>
                    @endforeach
                </nav>
            </div>

            {{-- ── Panel 1: Vault ───────────────────────────────────────── --}}
            <div id="tab-panel-vault" class="tab-panel @if($activeTab !== 'vault') hidden @endif">
                <form method="POST" action="{{ route('nfse.settings.vault') }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    @if($vaultReady)
                        <p id="vault-gate-ready-notice" class="text-sm text-green-700 bg-green-50 border border-green-300 rounded px-3 py-2">
                            {{ trans('nfse::general.settings.vault_gate_ready_notice') }}
                        </p>
                    @endif

                    {{-- Status summary (hidden metadata for JS) --}}
                    <div class="p-3 rounded border border-blue-300 bg-blue-50 text-blue-800 text-sm space-y-1">
                        <p class="font-semibold">{{ trans('nfse::general.settings.vault_status_title') }}</p>
                        <p id="vault-status-addr"      class="hidden" data-configured="{{ ($vaultUiState['addr_configured'] ?? false) ? '1' : '0' }}"></p>
                        <p id="vault-status-mount"     class="hidden" data-configured="{{ ($vaultUiState['mount_configured'] ?? false) ? '1' : '0' }}"></p>
                        <p id="vault-status-token"     class="hidden" data-configured="{{ ($vaultUiState['token_configured'] ?? false) ? '1' : '0' }}"></p>
                        <p id="vault-status-role-id"   class="hidden" data-configured="{{ ($vaultUiState['role_id_configured'] ?? false) ? '1' : '0' }}"></p>
                        <p id="vault-status-secret-id" class="hidden" data-configured="{{ ($vaultUiState['secret_id_configured'] ?? false) ? '1' : '0' }}"></p>
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
                        <input id="bao_mount" name="nfse[bao_mount]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_mount', setting('nfse.bao_mount', '/nfse')) }}">
                        <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_mount_hint') }}</p>
                    </div>

                    {{-- Auth mode fieldset --}}
                    <fieldset id="vault-auth-mode-fieldset" class="rounded-md border border-gray-200 p-3 space-y-2" aria-describedby="vault-auth-mode-hint">
                        <legend class="px-1 text-sm font-semibold">{{ trans('nfse::general.settings.auth_mode_group_label') }}</legend>
                        <p id="vault-auth-mode-hint" class="text-xs text-gray-500">{{ trans('nfse::general.settings.auth_mode_group_hint') }}</p>
                        <div class="flex gap-6 pt-1">
                            <label class="inline-flex items-center gap-2 cursor-pointer font-medium text-sm">
                                <input id="auth-mode-token" type="radio" name="auth_mode_ui" value="token"
                                    onclick="document.getElementById('vault-token-section')?.classList.remove('hidden'); document.getElementById('vault-token-section') && (document.getElementById('vault-token-section').hidden = false); document.getElementById('vault-approle-section')?.classList.add('hidden'); document.getElementById('vault-approle-section') && (document.getElementById('vault-approle-section').hidden = true);"
                                    @checked($selectedAuthMode === 'token')>
                                {{ trans('nfse::general.settings.auth_mode_option_token') }}
                            </label>
                            <label class="inline-flex items-center gap-2 cursor-pointer font-medium text-sm">
                                <input id="auth-mode-approle" type="radio" name="auth_mode_ui" value="approle"
                                    onclick="document.getElementById('vault-token-section')?.classList.add('hidden'); document.getElementById('vault-token-section') && (document.getElementById('vault-token-section').hidden = true); document.getElementById('vault-approle-section')?.classList.remove('hidden'); document.getElementById('vault-approle-section') && (document.getElementById('vault-approle-section').hidden = false);"
                                    @checked($selectedAuthMode === 'approle')>
                                {{ trans('nfse::general.settings.auth_mode_option_approle') }}
                            </label>
                        </div>

                        {{-- Token fields --}}
                        <div id="vault-token-section" class="space-y-4 @if($selectedAuthMode === 'approle') hidden @endif" @if($selectedAuthMode === 'approle') hidden @endif>
                        @php($showLocalTokenHint = app()->environment(['local', 'development']))
                        <div>
                            <label class="block text-sm font-medium mb-1" for="bao_token">{{ trans('nfse::general.settings.bao_token') }}</label>
                            <div class="relative">
                                <input id="bao_token" name="nfse[bao_token]" type="password" class="w-full border rounded px-3 py-2 pr-10" autocomplete="new-password" @if($showLocalTokenHint) placeholder="dev-only-root-token" @endif>
                                <button
                                    id="toggle-bao-token"
                                    type="button"
                                    class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-700"
                                    aria-label="{{ trans('nfse::general.settings.show_password') }}"
                                    onclick="const input = document.getElementById('bao_token'); const eyeOpen = this.querySelector('[data-eye-open]'); const eyeOff = this.querySelector('[data-eye-off]'); if (input) { input.type = input.type === 'password' ? 'text' : 'password'; const hidden = input.type === 'password'; eyeOpen?.classList.toggle('hidden', !hidden); eyeOff?.classList.toggle('hidden', hidden); this.setAttribute('aria-label', hidden ? '{{ trans('nfse::general.settings.show_password') }}' : '{{ trans('nfse::general.settings.hide_password') }}'); }"
                                >
                                    <svg data-eye-open xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M1.5 12s3.8-7 10.5-7 10.5 7 10.5 7-3.8 7-10.5 7S1.5 12 1.5 12z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg data-eye-off xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 hidden">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 6.2A9.8 9.8 0 0 1 12 6c6.7 0 10.5 6 10.5 6a18.8 18.8 0 0 1-4 4.8" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.9C3.7 8.7 1.5 12 1.5 12a18.7 18.7 0 0 0 5.6 6" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.9 10a3 3 0 0 0 4.1 4.1" />
                                    </svg>
                                    <span class="sr-only">{{ trans('nfse::general.settings.show_password') }}</span>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_token_hint') }}</p>
                            @if($showLocalTokenHint)
                                <p class="text-xs text-blue-700 mt-1">{{ trans('nfse::general.settings.bao_token_local_dev_hint') }}</p>
                            @endif
                            <label class="inline-flex items-center gap-2 mt-2 text-xs text-gray-700">
                                <input id="clear_bao_token" name="nfse[clear_bao_token]" type="checkbox" value="1" @checked((string) old('nfse.clear_bao_token', '0') === '1')>
                                <span>{{ trans('nfse::general.settings.clear_bao_token') }}</span>
                            </label>
                        </div>
                        </div>

                        {{-- AppRole fields --}}
                        <div id="vault-approle-section" class="space-y-4 @if($selectedAuthMode !== 'approle') hidden @endif" @if($selectedAuthMode !== 'approle') hidden @endif>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="bao_role_id">{{ trans('nfse::general.settings.bao_role_id') }}</label>
                                <input id="bao_role_id" name="nfse[bao_role_id]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_role_id', setting('nfse.bao_role_id')) }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="bao_secret_id">{{ trans('nfse::general.settings.bao_secret_id') }}</label>
                                <div class="relative">
                                    <input id="bao_secret_id" name="nfse[bao_secret_id]" type="password" class="w-full border rounded px-3 py-2 pr-10" autocomplete="new-password">
                                    <button
                                        id="toggle-bao-secret-id"
                                        type="button"
                                        class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-700"
                                        aria-label="{{ trans('nfse::general.settings.show_password') }}"
                                        onclick="const input = document.getElementById('bao_secret_id'); const eyeOpen = this.querySelector('[data-eye-open]'); const eyeOff = this.querySelector('[data-eye-off]'); if (input) { input.type = input.type === 'password' ? 'text' : 'password'; const hidden = input.type === 'password'; eyeOpen?.classList.toggle('hidden', !hidden); eyeOff?.classList.toggle('hidden', hidden); this.setAttribute('aria-label', hidden ? '{{ trans('nfse::general.settings.show_password') }}' : '{{ trans('nfse::general.settings.hide_password') }}'); }"
                                    >
                                        <svg data-eye-open xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M1.5 12s3.8-7 10.5-7 10.5 7 10.5 7-3.8 7-10.5 7S1.5 12 1.5 12z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                        <svg data-eye-off xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 hidden">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 6.2A9.8 9.8 0 0 1 12 6c6.7 0 10.5 6 10.5 6a18.8 18.8 0 0 1-4 4.8" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.9C3.7 8.7 1.5 12 1.5 12a18.7 18.7 0 0 0 5.6 6" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.9 10a3 3 0 0 0 4.1 4.1" />
                                        </svg>
                                        <span class="sr-only">{{ trans('nfse::general.settings.show_password') }}</span>
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.bao_secret_id_hint') }}</p>
                                <label class="inline-flex items-center gap-2 mt-2 text-xs text-gray-700">
                                    <input id="clear_bao_secret_id" name="nfse[clear_bao_secret_id]" type="checkbox" value="1" @checked((string) old('nfse.clear_bao_secret_id', '0') === '1')>
                                    <span>{{ trans('nfse::general.settings.clear_bao_secret_id') }}</span>
                                </label>
                            </div>
                        </div>
                    </fieldset>

                    <p class="text-xs text-gray-500">{{ trans('nfse::general.settings.sensitive_fields_behavior_hint') }}</p>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">
                            {{ trans('general.save') }}
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── Panel 2: Certificate ──────────────────────────────────── --}}
            <div id="tab-panel-certificate" class="tab-panel @if($activeTab !== 'certificate') hidden @endif">

                @if(!$vaultReady)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form id="certificate-form" method="POST" action="{{ route('nfse.certificate.upload') }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        <p class="text-sm text-gray-500">{{ trans('nfse::general.settings.certificate_hint') }}</p>

                        @if($hasSavedSettings)
                            <div class="p-3 rounded border border-blue-300 bg-blue-50 text-blue-800 text-sm space-y-1">
                                <p class="font-semibold">{{ trans('nfse::general.saved_state_title') }}</p>
                                <p>{{ trans('nfse::general.saved_state_cnpj') }} <span class="font-mono">{{ $certificateState['cnpj'] }}</span></p>
                                <p>
                                    {{ trans('nfse::general.saved_state_certificate') }}
                                    @if(($certificateState['has_local_certificate'] ?? false) === true)
                                        {{ trans('nfse::general.saved_state_certificate_present') }}
                                    @else
                                        {{ trans('nfse::general.saved_state_certificate_missing') }}
                                    @endif
                                </p>
                                <p>
                                    {{ trans('nfse::general.saved_state_vault_password') }}
                                    @if(($vaultUiState['certificate_secret_available'] ?? false) === true)
                                        {{ trans('nfse::general.saved_state_vault_password_present') }}
                                    @else
                                        {{ trans('nfse::general.saved_state_vault_password_missing') }}
                                    @endif
                                </p>
                                <div class="pt-2 flex flex-wrap gap-2">
                                    <button type="button" id="btn-show-replace-cert" class="inline-flex items-center px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700">
                                        {{ trans('nfse::general.replace_certificate') }}
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div id="replace-cert-fields" @if($hasSavedSettings) hidden @endif class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" for="pfx_file">{{ trans('nfse::general.settings.certificate') }}</label>
                                <input id="pfx_file" name="pfx_file" type="file" accept=".pfx,.p12" class="w-full border rounded px-3 py-2">
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1" for="pfx_password">{{ trans('nfse::general.settings.pfx_password') }}</label>
                                <div class="relative">
                                    <input id="pfx_password" name="pfx_password" type="password" class="w-full border rounded px-3 py-2 pr-10" autocomplete="new-password">
                                    <button
                                        id="toggle-pfx-password"
                                        type="button"
                                        class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-700"
                                        aria-label="{{ trans('nfse::general.settings.show_password') }}"
                                        onclick="const input = document.getElementById('pfx_password'); const eyeOpen = this.querySelector('[data-eye-open]'); const eyeOff = this.querySelector('[data-eye-off]'); if (input) { input.type = input.type === 'password' ? 'text' : 'password'; const hidden = input.type === 'password'; eyeOpen?.classList.toggle('hidden', !hidden); eyeOff?.classList.toggle('hidden', hidden); this.setAttribute('aria-label', hidden ? '{{ trans('nfse::general.settings.show_password') }}' : '{{ trans('nfse::general.settings.hide_password') }}'); }"
                                    >
                                        <svg data-eye-open xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M1.5 12s3.8-7 10.5-7 10.5 7 10.5 7-3.8 7-10.5 7S1.5 12 1.5 12z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                        <svg data-eye-off xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 hidden">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 6.2A9.8 9.8 0 0 1 12 6c6.7 0 10.5 6 10.5 6a18.8 18.8 0 0 1-4 4.8" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.9C3.7 8.7 1.5 12 1.5 12a18.7 18.7 0 0 0 5.6 6" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.9 10a3 3 0 0 0 4.1 4.1" />
                                        </svg>
                                        <span class="sr-only">{{ trans('nfse::general.settings.show_password') }}</span>
                                    </button>
                                </div>
                            </div>

                            <p class="text-xs text-gray-500">{{ trans('nfse::general.settings.edit_hint_without_certificate') }}</p>

                            <div id="cert-cnpj-display" class="hidden flex items-center gap-2 p-3 bg-green-50 border border-green-300 rounded">
                                <span class="text-sm text-green-700">{{ trans('nfse::general.cnpj_from_certificate') }}</span>
                                <span id="cert-cnpj-value" class="font-mono font-bold text-green-900"></span>
                            </div>

                            <div id="cert-error-display" class="hidden text-red-600 text-sm"></div>

                            <div class="flex flex-wrap gap-3">
                                <button type="button" id="btn-read-cert" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                    {{ trans('nfse::general.read_certificate') }}
                                </button>
                            </div>
                        </div>

                        @if($hasSavedSettings)
                            <div class="border-t border-gray-200 pt-3">
                                <button type="button" id="btn-delete-certificate" class="inline-flex items-center px-3 py-1.5 rounded bg-red-600 text-white hover:bg-red-700 text-sm">
                                    {{ trans('nfse::general.delete_certificate_and_settings') }}
                                </button>
                            </div>
                        @endif

                        <div class="flex justify-end pt-2">
                            <button id="btn-upload-cert" type="submit" class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed" @if(!$hasSavedSettings) disabled @endif>
                                {{ trans('general.save') }}
                            </button>
                        </div>
                    </form>

                    {{-- Delete certificate form (separate, no enctype) --}}
                    <form id="delete-certificate-form" method="POST" action="{{ route('nfse.certificate.destroy') }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            </div>

            {{-- ── Panel 3: Fiscal data ──────────────────────────────────── --}}
            <div id="tab-panel-fiscal" class="tab-panel @if($activeTab !== 'fiscal') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form method="POST" action="{{ route('nfse.settings.fiscal') }}" class="space-y-4">
                        @csrf
                        @method('PATCH')

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
                            <label class="block text-sm font-medium mb-1" for="opcao_simples_nacional">{{ trans('nfse::general.settings.opcao_simples_nacional') }}</label>
                            <select id="opcao_simples_nacional" name="nfse[opcao_simples_nacional]" class="w-full border rounded px-3 py-2">
                                <option value="1" @selected((string) old('nfse.opcao_simples_nacional', setting('nfse.opcao_simples_nacional', 2)) === '1')>{{ trans('nfse::general.settings.opcao_simples_nacional_not_optant') }}</option>
                                <option value="2" @selected((string) old('nfse.opcao_simples_nacional', setting('nfse.opcao_simples_nacional', 2)) === '2')>{{ trans('nfse::general.settings.opcao_simples_nacional_optant') }}</option>
                            </select>
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input name="nfse[sandbox_mode]" type="checkbox" value="1" @checked((bool) old('nfse.sandbox_mode', setting('nfse.sandbox_mode', true)))>
                            <span>{{ trans('nfse::general.settings.sandbox_mode') }}</span>
                        </label>

                        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 md:flex md:items-center md:justify-between">
                            <p class="text-sm text-green-900 mb-2 md:mb-0">
                                {{ trans('nfse::general.settings.federal.helper') }}
                            </p>
                            <button id="federal-save-button" type="submit" class="inline-flex w-full md:w-auto justify-center items-center px-5 py-2.5 rounded bg-green-700 text-white hover:bg-green-800 font-semibold shadow-sm">
                                {{ trans('general.save') }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- ── Panel 4: Federal taxation ─────────────────────────────── --}}
            <div id="tab-panel-federal" class="tab-panel @if($activeTab !== 'federal') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form method="POST" action="{{ route('nfse.settings.federal') }}" class="space-y-4">
                        @csrf
                        @method('PATCH')

                        <h3 class="text-base font-semibold text-gray-900">{{ trans('nfse::general.settings.federal.heading') }}</h3>

                        <p class="text-sm text-gray-600">{{ trans('nfse::general.settings.federal.helper') }}</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" for="federal-piscofins-situacao">{{ trans('nfse::general.settings.federal.piscofins_situacao_tributaria') }}</label>
                                <select id="federal-piscofins-situacao" name="nfse[federal_piscofins_situacao_tributaria]" class="w-full border rounded px-3 py-2">
                                    <option value="">{{ trans('nfse::general.settings.federal.select_placeholder') }}</option>
                                    <option value="0" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '0')>00 - Nenhum</option>
                                    <option value="1" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '1')>01 - Operacao Tributavel com Aliquota Basica</option>
                                    <option value="2" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '2')>02 - Operacao Tributavel com Aliquota Diferenciada</option>
                                    <option value="3" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '3')>03 - Operacao Tributavel com Aliquota por Unidade de Medida de Produto</option>
                                    <option value="4" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '4')>04 - Operacao Tributavel monofasica - Revenda a Aliquota Zero</option>
                                    <option value="5" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '5')>05 - Operacao Tributavel por Substituicao Tributaria</option>
                                    <option value="6" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '6')>06 - Operacao Tributavel a Aliquota Zero</option>
                                    <option value="7" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '7')>07 - Operacao Isenta da Contribuicao</option>
                                    <option value="8" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '8')>08 - Operacao sem Incidencia da Contribuicao</option>
                                    <option value="9" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '9')>09 - Operacao com Suspensao da Contribuicao</option>
                                    <option value="49" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '49')>49 - Outras Operacoes de Saida</option>
                                    <option value="50" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '50')>50 - Operacao com Direito a Credito</option>
                                    <option value="51" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '51')>51 - Operacao com Direito a Credito nao tributada</option>
                                    <option value="52" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '52')>52 - Operacao com Direito a Credito para exportacao</option>
                                    <option value="53" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '53')>53 - Credito para receitas tributadas e nao tributadas</option>
                                    <option value="54" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '54')>54 - Credito para receitas internas e exportacao</option>
                                    <option value="55" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '55')>55 - Credito para receitas nao tributadas e exportacao</option>
                                    <option value="56" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '56')>56 - Credito para receitas mistas</option>
                                    <option value="60" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '60')>60 - Credito Presumido</option>
                                    <option value="61" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '61')>61 - Credito Presumido nao tributada</option>
                                    <option value="62" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '62')>62 - Credito Presumido exportacao</option>
                                    <option value="63" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '63')>63 - Credito Presumido receitas mistas internas</option>
                                    <option value="64" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '64')>64 - Credito Presumido interno e exportacao</option>
                                    <option value="65" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '65')>65 - Credito Presumido nao tributada e exportacao</option>
                                    <option value="66" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '66')>66 - Credito Presumido receitas mistas</option>
                                    <option value="67" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '67')>67 - Credito Presumido outras operacoes</option>
                                    <option value="70" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '70')>70 - Operacao de Aquisicao sem Direito a Credito</option>
                                    <option value="71" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '71')>71 - Operacao de Aquisicao com Isencao</option>
                                    <option value="72" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '72')>72 - Operacao de Aquisicao com Suspensao</option>
                                    <option value="73" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '73')>73 - Operacao de Aquisicao a Aliquota Zero</option>
                                    <option value="74" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '74')>74 - Operacao de Aquisicao sem Incidencia</option>
                                    <option value="75" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '75')>75 - Operacao de Aquisicao por Substituicao Tributaria</option>
                                    <option value="98" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '98')>98 - Outras Operacoes de Entrada</option>
                                    <option value="99" @selected((string) old('nfse.federal_piscofins_situacao_tributaria', setting('nfse.federal_piscofins_situacao_tributaria', '')) === '99')>99 - Outras Operacoes</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1" for="federal-piscofins-tipo-retencao">{{ trans('nfse::general.settings.federal.piscofins_tipo_retencao') }}</label>
                                <select id="federal-piscofins-tipo-retencao" name="nfse[federal_piscofins_tipo_retencao]" class="w-full border rounded px-3 py-2">
                                    <option value="">{{ trans('nfse::general.settings.federal.select_placeholder') }}</option>
                                    <option value="0" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '0')>PIS/COFINS/CSLL Nao Retidos</option>
                                    <option value="3" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '3')>PIS/COFINS/CSLL Retidos</option>
                                    <option value="4" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '4')>PIS/COFINS Retidos, CSLL Nao Retido</option>
                                    <option value="5" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '5')>PIS Retido, COFINS/CSLL Nao Retido</option>
                                    <option value="6" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '6')>COFINS Retido, PIS/CSLL Nao Retido</option>
                                    <option value="7" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '7')>PIS Nao Retido, COFINS/CSLL Retidos</option>
                                    <option value="8" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '8')>PIS/COFINS Nao Retidos, CSLL Retido</option>
                                    <option value="9" @selected((string) old('nfse.federal_piscofins_tipo_retencao', setting('nfse.federal_piscofins_tipo_retencao', '')) === '9')>COFINS Nao Retido, PIS/CSLL Retidos</option>
                                </select>
                            </div>
                        </div>

                        <div id="federal-piscofins-panel" class="rounded-md border border-gray-200 p-3 space-y-4 hidden">
                            <div id="federal-piscofins-bc-row">
                                <label class="block text-sm font-medium mb-1" for="federal_piscofins_base_calculo">{{ trans('nfse::general.settings.federal.piscofins_base_calculo') }}</label>
                                <div class="relative">
                                    <input id="federal_piscofins_base_calculo" name="nfse[federal_piscofins_base_calculo]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2 federal-piscofins-field" value="{{ old('nfse.federal_piscofins_base_calculo', setting('nfse.federal_piscofins_base_calculo', '')) }}" placeholder="0.00">
                                    <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="federal_piscofins_aliquota_pis">{{ trans('nfse::general.settings.federal.piscofins_aliquota_pis') }}</label>
                                    <div class="relative">
                                        <input id="federal_piscofins_aliquota_pis" name="nfse[federal_piscofins_aliquota_pis]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12 federal-piscofins-field" value="{{ old('nfse.federal_piscofins_aliquota_pis', setting('nfse.federal_piscofins_aliquota_pis', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div id="federal-piscofins-pis-valor-col">
                                    <label class="block text-sm font-medium mb-1" for="federal_piscofins_valor_pis">{{ trans('nfse::general.settings.federal.piscofins_valor_pis') }}</label>
                                    <div class="relative">
                                        <input id="federal_piscofins_valor_pis" name="nfse[federal_piscofins_valor_pis]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2 federal-piscofins-field" value="{{ old('nfse.federal_piscofins_valor_pis', setting('nfse.federal_piscofins_valor_pis', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="federal_piscofins_aliquota_cofins">{{ trans('nfse::general.settings.federal.piscofins_aliquota_cofins') }}</label>
                                    <div class="relative">
                                        <input id="federal_piscofins_aliquota_cofins" name="nfse[federal_piscofins_aliquota_cofins]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12 federal-piscofins-field" value="{{ old('nfse.federal_piscofins_aliquota_cofins', setting('nfse.federal_piscofins_aliquota_cofins', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div id="federal-piscofins-cofins-valor-col">
                                    <label class="block text-sm font-medium mb-1" for="federal_piscofins_valor_cofins">{{ trans('nfse::general.settings.federal.piscofins_valor_cofins') }}</label>
                                    <div class="relative">
                                        <input id="federal_piscofins_valor_cofins" name="nfse[federal_piscofins_valor_cofins]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2 federal-piscofins-field" value="{{ old('nfse.federal_piscofins_valor_cofins', setting('nfse.federal_piscofins_valor_cofins', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" for="federal_valor_irrf">{{ trans('nfse::general.settings.federal.valor_irrf') }}</label>
                                <div class="relative">
                                    <input id="federal_valor_irrf" name="nfse[federal_valor_irrf]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2" value="{{ old('nfse.federal_valor_irrf', setting('nfse.federal_valor_irrf', '')) }}" placeholder="0.00">
                                    <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="federal_valor_csll">{{ trans('nfse::general.settings.federal.valor_csll') }}</label>
                                <div class="relative">
                                    <input id="federal_valor_csll" name="nfse[federal_valor_csll]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2" value="{{ old('nfse.federal_valor_csll', setting('nfse.federal_valor_csll', '')) }}" placeholder="0.00">
                                    <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="federal_valor_cp">{{ trans('nfse::general.settings.federal.valor_cp') }}</label>
                                <div class="relative">
                                    <input id="federal_valor_cp" name="nfse[federal_valor_cp]" type="text" inputmode="decimal" class="w-full border rounded pl-12 pr-3 py-2" value="{{ old('nfse.federal_valor_cp', setting('nfse.federal_valor_cp', '')) }}" placeholder="0.00">
                                    <span data-tax-affix="money" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-sm text-gray-400">R$</span>
                                </div>
                            </div>
                        </div>

                        <fieldset class="rounded-md border border-gray-200 p-3 space-y-2">
                            <legend class="px-1 text-sm font-semibold">{{ trans('nfse::general.settings.federal.behavior_label') }}</legend>
                            <p class="text-xs text-gray-500">{{ trans('nfse::general.settings.federal.behavior_hint') }}</p>

                            <div class="space-y-2 pt-1">
                                <label class="inline-flex items-center gap-2 cursor-pointer font-medium text-sm">
                                    <input type="radio" name="nfse[tributacao_federal_mode]" value="per_invoice_amounts" @checked($selectedFederalMode === 'per_invoice_amounts')>
                                    {{ trans('nfse::general.settings.federal.mode_per_invoice_amounts') }}
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer font-medium text-sm">
                                    <input type="radio" name="nfse[tributacao_federal_mode]" value="percentage_profile" @checked($selectedFederalMode === 'percentage_profile')>
                                    {{ trans('nfse::general.settings.federal.mode_percentage_profile') }}
                                </label>
                            </div>
                        </fieldset>

                        <div id="federal-tributos-percent-rows" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_fed_p">{{ trans('nfse::general.settings.federal.tributos_fed_p') }}</label>
                                    <div class="relative">
                                        <input id="tributos_fed_p" name="nfse[tributos_fed_p]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_fed_p', setting('nfse.tributos_fed_p', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_est_p">{{ trans('nfse::general.settings.federal.tributos_est_p') }}</label>
                                    <div class="relative">
                                        <input id="tributos_est_p" name="nfse[tributos_est_p]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_est_p', setting('nfse.tributos_est_p', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_mun_p">{{ trans('nfse::general.settings.federal.tributos_mun_p') }}</label>
                                    <div class="relative">
                                        <input id="tributos_mun_p" name="nfse[tributos_mun_p]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_mun_p', setting('nfse.tributos_mun_p', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_fed_sn">{{ trans('nfse::general.settings.federal.tributos_fed_sn') }}</label>
                                    <div class="relative">
                                        <input id="tributos_fed_sn" name="nfse[tributos_fed_sn]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_fed_sn', setting('nfse.tributos_fed_sn', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_est_sn">{{ trans('nfse::general.settings.federal.tributos_est_sn') }}</label>
                                    <div class="relative">
                                        <input id="tributos_est_sn" name="nfse[tributos_est_sn]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_est_sn', setting('nfse.tributos_est_sn', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributos_mun_sn">{{ trans('nfse::general.settings.federal.tributos_mun_sn') }}</label>
                                    <div class="relative">
                                        <input id="tributos_mun_sn" name="nfse[tributos_mun_sn]" type="text" inputmode="decimal" class="w-full border rounded px-3 py-2 pr-12" value="{{ old('nfse.tributos_mun_sn', setting('nfse.tributos_mun_sn', '')) }}" placeholder="0.00">
                                        <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">
                                {{ trans('general.save') }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- ── Panel 5: Services ─────────────────────────────────────── --}}
            <div id="tab-panel-services" class="tab-panel @if($activeTab !== 'services') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    @include('nfse::settings.partials.services')
                @endif
            </div>

        </div>

        <script>
            document.addEventListener('DOMContentLoaded', async () => {

                // ── Tab switcher ────────────────────────────────────────────
                document.querySelectorAll('.tab-button').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') {
                            return;
                        }

                        document.querySelectorAll('.tab-button').forEach((b) => {
                            b.classList.remove('border-green-600', 'text-green-700');
                            b.classList.add('border-transparent', 'text-gray-500');
                        });

                        document.querySelectorAll('.tab-panel').forEach((p) => {
                            p.classList.add('hidden');
                        });

                        btn.classList.add('border-green-600', 'text-green-700');
                        btn.classList.remove('border-transparent', 'text-gray-500');
                        document.getElementById('tab-panel-' + btn.dataset.tab)?.classList.remove('hidden');
                    });
                });

                // ── Auth mode toggle (Token / AppRole) ──────────────────────
                const vaultTokenSection   = document.getElementById('vault-token-section');
                const vaultApproleSection = document.getElementById('vault-approle-section');

                const setSectionVisibility = (element, isVisible) => {
                    if (!element) return;
                    element.hidden = !isVisible;
                    element.classList.toggle('hidden', !isVisible);
                };

                document.querySelectorAll('input[name="auth_mode_ui"]').forEach((radio) => {
                    radio.addEventListener('change', () => {
                        setSectionVisibility(vaultTokenSection, radio.value === 'token');
                        setSectionVisibility(vaultApproleSection, radio.value === 'approle');
                    });
                });

                // ── Certificate tab: read cert + upload button ──────────────
                const btnReadCert       = document.getElementById('btn-read-cert');
                const btnUploadCert     = document.getElementById('btn-upload-cert');
                const pfxFileInput      = document.getElementById('pfx_file');
                const pfxPasswordInput  = document.getElementById('pfx_password');
                const certCnpjDisplay   = document.getElementById('cert-cnpj-display');
                const certCnpjValue     = document.getElementById('cert-cnpj-value');
                const certErrorDisplay  = document.getElementById('cert-error-display');
                const replaceFields     = document.getElementById('replace-cert-fields');
                const showReplaceButton = document.getElementById('btn-show-replace-cert');
                const deleteCertBtn     = document.getElementById('btn-delete-certificate');
                const deleteForm        = document.getElementById('delete-certificate-form');
                const certificateForm   = document.getElementById('certificate-form');
                const csrfToken         = certificateForm?.querySelector('input[name="_token"]')?.value ?? '';

                const parsePfxUrl      = @json(route('nfse.certificate.parse'));
                const hasSavedSettings = @json(($certificateState['has_saved_settings'] ?? false) === true);

                const syncCertButtons = () => {
                    const hasFile     = (pfxFileInput?.files?.length ?? 0) > 0;
                    const hasPassword = (pfxPasswordInput?.value?.trim() ?? '').length > 0;

                    if (btnReadCert) {
                        btnReadCert.disabled = !hasFile || !hasPassword;
                    }

                    if (btnUploadCert) {
                        btnUploadCert.disabled = !hasSavedSettings && (!hasFile || !hasPassword);
                    }
                };

                pfxFileInput?.addEventListener('change', syncCertButtons);
                pfxPasswordInput?.addEventListener('input', syncCertButtons);
                syncCertButtons();

                showReplaceButton?.addEventListener('click', () => {
                    if (replaceFields) replaceFields.hidden = false;
                    pfxFileInput?.focus();
                    syncCertButtons();
                });

                deleteCertBtn?.addEventListener('click', () => {
                    if (!confirm(@json(trans('nfse::general.confirm_delete_certificate_and_settings')))) {
                        return;
                    }
                    deleteForm?.submit();
                });

                btnReadCert?.addEventListener('click', async () => {
                    certErrorDisplay?.classList.add('hidden');
                    certCnpjDisplay?.classList.add('hidden');

                    if (!pfxFileInput?.files?.length) {
                        if (certErrorDisplay) {
                            certErrorDisplay.textContent = @json(trans('nfse::general.settings.certificate')) + ': selecione um arquivo PFX.';
                            certErrorDisplay.classList.remove('hidden');
                        }
                        return;
                    }

                    btnReadCert.disabled = true;

                    const formData = new FormData();
                    formData.append('pfx_file', pfxFileInput.files[0]);
                    formData.append('pfx_password', pfxPasswordInput?.value ?? '');
                    formData.append('_token', csrfToken);

                    try {
                        const response = await fetch(parsePfxUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: formData,
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            if (certErrorDisplay) {
                                certErrorDisplay.textContent = payload.error ?? @json(trans('nfse::general.invalid_pfx'));
                                certErrorDisplay.classList.remove('hidden');
                            }
                            return;
                        }

                        const cnpj = payload.data?.cnpj ?? null;

                        if (!cnpj) {
                            if (certErrorDisplay) {
                                certErrorDisplay.textContent = @json(trans('nfse::general.cnpj_not_found'));
                                certErrorDisplay.classList.remove('hidden');
                            }
                            return;
                        }

                        if (certCnpjValue) certCnpjValue.textContent = cnpj;
                        certCnpjDisplay?.classList.remove('hidden');
                        syncCertButtons();
                    } catch {
                        if (certErrorDisplay) {
                            certErrorDisplay.textContent = @json(trans('nfse::general.invalid_pfx'));
                            certErrorDisplay.classList.remove('hidden');
                        }
                    } finally {
                        btnReadCert.disabled = false;
                    }
                });

                // ── Federal tab: official-like PIS/COFINS interactions ────
                const federalSituacao = document.getElementById('federal-piscofins-situacao');
                const federalTipoRetencao = document.getElementById('federal-piscofins-tipo-retencao');
                const federalPanel = document.getElementById('federal-piscofins-panel');
                const federalCsll = document.getElementById('federal_valor_csll');
                const federalFields = Array.from(document.querySelectorAll('.federal-piscofins-field'));

                const blockPiscofinsFields = (blockAndZero) => {
                    federalFields.forEach((field) => {
                        if (!(field instanceof HTMLInputElement)) {
                            return;
                        }

                        if (blockAndZero) {
                            field.value = '0.00';
                            field.readOnly = true;
                            field.classList.add('bg-gray-50');
                        } else {
                            if (field.value === '0.00') {
                                field.value = '';
                            }
                            field.readOnly = false;
                            field.classList.remove('bg-gray-50');
                        }
                    });
                };

                const syncFederalPanel = () => {
                    if (!(federalSituacao instanceof HTMLSelectElement)) {
                        return;
                    }

                    const situacao = federalSituacao.value;
                    const showPiscofins = situacao !== '' && situacao !== '0';

                    if (federalPanel) {
                        federalPanel.classList.toggle('hidden', !showPiscofins);
                    }

                    if (!showPiscofins) {
                        if (federalTipoRetencao instanceof HTMLSelectElement) {
                            federalTipoRetencao.value = '';
                        }

                        federalFields.forEach((field) => {
                            if (field instanceof HTMLInputElement) {
                                field.value = '';
                                field.readOnly = false;
                                field.classList.remove('bg-gray-50');
                            }
                        });
                    }

                    blockPiscofinsFields(situacao === '4' || situacao === '6');
                };

                const syncFederalRetencao = () => {
                    if (!(federalTipoRetencao instanceof HTMLSelectElement) || !(federalCsll instanceof HTMLInputElement)) {
                        return;
                    }

                    const hasCsllRetention = federalTipoRetencao.value !== '' && federalTipoRetencao.value !== '0';

                    federalCsll.readOnly = !hasCsllRetention;
                    federalCsll.classList.toggle('bg-gray-50', !hasCsllRetention);

                    if (!hasCsllRetention) {
                        federalCsll.value = '';
                    }
                };

                federalSituacao?.addEventListener('change', () => {
                    syncFederalPanel();
                    syncFederalRetencao();
                });

                federalTipoRetencao?.addEventListener('change', () => {
                    syncFederalRetencao();
                });

                syncFederalPanel();
                syncFederalRetencao();

                // ── Federal mode toggle: per_invoice_amounts ↔ percentage_profile ──
                const federalModeRadios = document.querySelectorAll('input[name="nfse[tributacao_federal_mode]"]');
                const federalBcRow = document.getElementById('federal-piscofins-bc-row');
                const federalPisValorCol = document.getElementById('federal-piscofins-pis-valor-col');
                const federalCofinsValorCol = document.getElementById('federal-piscofins-cofins-valor-col');
                const tributosPercentRows = document.getElementById('federal-tributos-percent-rows');

                const syncFederalMode = () => {
                    const mode = document.querySelector('input[name="nfse[tributacao_federal_mode]"]:checked')?.value ?? 'per_invoice_amounts';
                    const isPercentage = mode === 'percentage_profile';

                    // In percentage_profile mode: BC and valor fields are auto-calculated at emission time
                    federalBcRow?.classList.toggle('hidden', isPercentage);
                    federalPisValorCol?.classList.toggle('hidden', isPercentage);
                    federalCofinsValorCol?.classList.toggle('hidden', isPercentage);

                    // Percentage profile rows only relevant in percentage_profile mode
                    tributosPercentRows?.classList.toggle('hidden', !isPercentage);
                };

                federalModeRadios.forEach((radio) => {
                    radio.addEventListener('change', syncFederalMode);
                });

                syncFederalMode();

                // ── Fiscal tab: UF / municipality / LC116 ───────────────────
                const ufSelect           = document.getElementById('uf');
                const municipalitySelect = document.getElementById('municipio_nome');
                const ibgeHidden         = document.getElementById('municipio_ibge');
                const ibgeDisplay        = document.getElementById('municipio_ibge_display');
                if (!ufSelect) {
                    return; // fiscal tab not rendered (no saved settings)
                }

                const selectedUf              = @json(old('nfse.uf', setting('nfse.uf', '')));
                const selectedMunicipalityName = @json(old('nfse.municipio_nome', setting('nfse.municipio_nome', '')));
                const selectedIbge            = @json(old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '')));

                const ufsUrl                   = @json(route('nfse.ibge.ufs'));
                const municipalitiesUrlTemplate = @json(route('nfse.ibge.municipalities', ['uf' => '__UF__']));

                const fetchJson = async (url) => {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const payload  = await response.json().catch(() => ({ data: [] }));
                    if (!response.ok) return [];
                    return Array.isArray(payload.data) ? payload.data : [];
                };

                const renderMunicipalities = (municipalities, selectedName, selectedCode) => {
                    municipalitySelect.innerHTML = '';
                    const placeholder       = document.createElement('option');
                    placeholder.value       = '';
                    placeholder.textContent = 'Selecione...';
                    municipalitySelect.appendChild(placeholder);

                    municipalities.forEach((city) => {
                        const option        = document.createElement('option');
                        option.value        = city.name;
                        option.textContent  = city.name;
                        option.dataset.ibge = city.ibge_code;

                        if ((selectedCode && city.ibge_code === selectedCode) || (!selectedCode && selectedName && city.name === selectedName)) {
                            option.selected   = true;
                            ibgeHidden.value  = city.ibge_code;
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
                        ibgeHidden.value  = '';
                        ibgeDisplay.value = '';
                        return;
                    }

                    municipalitySelect.disabled = true;
                    municipalitySelect.innerHTML = '<option value="">Carregando municípios...</option>';

                    const url            = municipalitiesUrlTemplate.replace('__UF__', encodeURIComponent(uf));
                    const municipalities = await fetchJson(url);
                    renderMunicipalities(municipalities, preferredName, preferredCode);
                };

                ufSelect.addEventListener('change', async () => {
                    await loadMunicipalities(ufSelect.value);
                });

                municipalitySelect.addEventListener('change', () => {
                    const selectedOption = municipalitySelect.options[municipalitySelect.selectedIndex];
                    const ibgeCode       = selectedOption?.dataset?.ibge ?? '';
                    ibgeHidden.value     = ibgeCode;
                    ibgeDisplay.value    = ibgeCode;
                });

                const ufs = await fetchJson(ufsUrl);

                ufSelect.innerHTML = '';
                const ufPlaceholder       = document.createElement('option');
                ufPlaceholder.value       = '';
                ufPlaceholder.textContent = 'Selecione...';
                ufSelect.appendChild(ufPlaceholder);

                ufs.forEach((entry) => {
                    const option       = document.createElement('option');
                    option.value       = entry.uf;
                    option.textContent = `${entry.uf} - ${entry.name}`;
                    if (entry.uf === selectedUf) option.selected = true;
                    ufSelect.appendChild(option);
                });

                if (selectedUf) {
                    await loadMunicipalities(selectedUf, selectedMunicipalityName, selectedIbge);
                }
            });
        </script>
    </x-slot>
</x-layouts.admin>
