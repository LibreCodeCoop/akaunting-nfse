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

            @if(session('info'))
                <div class="bg-blue-50 border border-blue-300 text-blue-700 px-4 py-3 rounded mb-4">
                    {{ session('info') }}
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
                    'federal'     => ['label' => trans('nfse::general.settings.federal.tab_title'),  'enabled' => $hasSavedSettings],
                    'artifacts'   => ['label' => trans('nfse::general.settings.artifacts.tab_title'),'enabled' => $hasSavedSettings],
                ];

            @endphp

            <section
                id="nfse-readiness-summary"
                class="mb-6 rounded border px-4 py-4 {{ ($readiness['isReady'] ?? false) ? 'border-green-300 bg-green-50' : 'border-yellow-300 bg-yellow-50' }}"
                aria-labelledby="nfse-readiness-title"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="nfse-readiness-title" class="font-semibold">
                            {{ trans('nfse::general.readiness.title') }}
                        </h2>
                        <p class="mt-1 text-sm">
                            {{ ($readiness['isReady'] ?? false)
                                ? trans('nfse::general.readiness.ready')
                                : trans('nfse::general.readiness.hint') }}
                        </p>
                    </div>
                    <span class="text-sm font-medium">
                        {{ ($readiness['isReady'] ?? false)
                            ? trans('nfse::general.readiness.ready')
                            : trans('nfse::general.readiness.not_ready') }}
                    </span>
                </div>

                @if(!($readiness['isReady'] ?? false))
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach(($readiness['checklist'] ?? []) as $check => $passed)
                            @continue($passed)
                            @php
                                $readinessTabs = [
                                    'runtime_contract' => 'certificate',
                                    'bao_addr' => 'vault',
                                    'bao_mount' => 'vault',
                                    'vault_auth' => 'vault',
                                    'cnpj_prestador' => 'certificate',
                                    'certificate_cnpj_matches' => 'certificate',
                                    'municipio_ibge' => 'fiscal',
                                    'certificate' => 'certificate',
                                    'certificate_secret' => 'certificate',
                                    'certificate_valid' => 'certificate',
                                    'ibs_cbs' => 'federal',
                                ];
                                $targetTab = $readinessTabs[$check] ?? 'fiscal';
                            @endphp
                            <li class="flex items-center justify-between gap-4">
                                <span>{{ trans('nfse::general.readiness.checks.' . $check) }}</span>
                                <a
                                    href="{{ route('nfse.settings.edit', ['tab' => $targetTab]) }}"
                                    class="font-medium text-green-700 hover:underline"
                                >
                                    {{ trans('nfse::general.go_to_settings') }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ── Tab navigation ──────────────────────────────────────── --}}
            <div class="border-b border-gray-200 mb-6">
                <nav class="-mb-px flex" role="tablist" aria-label="{{ trans('nfse::general.settings.title') }}">
                    @foreach($tabs as $tabKey => $tab)
                        <button
                            type="button"
                            data-tab="{{ $tabKey }}"
                            id="tab-btn-{{ $tabKey }}"
                            role="tab"
                            aria-controls="tab-panel-{{ $tabKey }}"
                            aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                            tabindex="{{ $activeTab === $tabKey && $tab['enabled'] ? '0' : '-1' }}"
                            class="tab-button px-5 py-3 text-sm font-medium border-b-2 whitespace-nowrap {{ $activeTab === $tabKey ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} {{ !$tab['enabled'] ? 'opacity-40 cursor-not-allowed' : '' }}"
                            @if(!$tab['enabled']) disabled aria-disabled="true" @endif
                        >{{ $tab['label'] }}</button>
                    @endforeach
                </nav>
            </div>

            {{-- ── Panel 1: Vault ───────────────────────────────────────── --}}
            <div id="tab-panel-vault" role="tabpanel" aria-labelledby="tab-btn-vault" aria-hidden="{{ $activeTab === 'vault' ? 'false' : 'true' }}" class="tab-panel @if($activeTab !== 'vault') hidden @endif">
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
                        <input id="bao_addr" name="nfse[bao_addr]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.bao_addr', setting('nfse.bao_addr', '')) }}" required>
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
                        @php
                            $showLocalTokenHint = app()->environment(['local', 'development']);
                        @endphp
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
            <div id="tab-panel-certificate" role="tabpanel" aria-labelledby="tab-btn-certificate" aria-hidden="{{ $activeTab === 'certificate' ? 'false' : 'true' }}" class="tab-panel @if($activeTab !== 'certificate') hidden @endif">

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
                                <p id="certificate-validity-state">
                                    {{ trans('nfse::general.saved_state_certificate_valid_to') }}
                                    @if(($certificateState['validity_known'] ?? false) === true)
                                        {{ date('d/m/Y', (int) $certificateState['valid_to']) }}
                                        —
                                        @if(($certificateState['is_currently_valid'] ?? false) !== true)
                                            <span class="text-red-700 font-semibold">{{ trans('nfse::general.saved_state_certificate_expired') }}</span>
                                        @elseif(($certificateState['expires_soon'] ?? false) === true)
                                            <span class="text-amber-700 font-semibold">{{ trans('nfse::general.saved_state_certificate_expiring', ['days' => $certificateState['days_until_expiry']]) }}</span>
                                        @else
                                            <span class="text-green-700">{{ trans('nfse::general.saved_state_certificate_valid') }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-600">{{ trans('nfse::general.saved_state_certificate_validity_unknown') }}</span>
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
            <div id="tab-panel-fiscal" role="tabpanel" aria-labelledby="tab-btn-fiscal" aria-hidden="{{ $activeTab === 'fiscal' ? 'false' : 'true' }}" class="tab-panel @if($activeTab !== 'fiscal') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form id="fiscal-form" method="POST" action="{{ route('nfse.settings.fiscal') }}" class="space-y-4">
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

                        <div>
                            <label class="block text-sm font-medium mb-1" for="emission_policy">{{ trans('nfse::general.settings.emission_policy') }}</label>
                            <select id="emission_policy" name="nfse[emission_policy]" class="w-full border rounded px-3 py-2">
                                <option value="manual" @selected((string) old('nfse.emission_policy', setting('nfse.emission_policy', 'manual')) === 'manual')>
                                    {{ trans('nfse::general.settings.emission_policy_manual') }}
                                </option>
                                <option value="emit_on_send" @selected((string) old('nfse.emission_policy', setting('nfse.emission_policy', 'manual')) === 'emit_on_send')>
                                    {{ trans('nfse::general.settings.emission_policy_emit_on_send') }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.settings.emission_policy_help') }}</p>
                        </div>

                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">{{ trans('nfse::general.settings.issqn_special.heading') }}</h4>
                                <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.issqn_special.help') }}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tributacao_issqn">{{ trans('nfse::general.settings.issqn_special.tributacao') }}</label>
                                    <select id="tributacao_issqn" name="nfse[tributacao_issqn]" class="w-full border rounded px-3 py-2">
                                        @foreach([
                                            '1' => trans('nfse::general.settings.issqn_special.tributacao_taxable'),
                                            '2' => trans('nfse::general.settings.issqn_special.tributacao_immunity'),
                                            '3' => trans('nfse::general.settings.issqn_special.tributacao_export'),
                                            '4' => trans('nfse::general.settings.issqn_special.tributacao_non_incidence'),
                                        ] as $value => $label)
                                            <option value="{{ $value }}" @selected((string) old('nfse.tributacao_issqn', setting('nfse.tributacao_issqn', 1)) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="tipo_retencao_iss">{{ trans('nfse::general.settings.issqn_special.withholding') }}</label>
                                    <select id="tipo_retencao_iss" name="nfse[tipo_retencao_iss]" class="w-full border rounded px-3 py-2">
                                        <option value="1" @selected((string) old('nfse.tipo_retencao_iss', setting('nfse.tipo_retencao_iss', 1)) === '1')>{{ trans('nfse::general.settings.issqn_special.withholding_none') }}</option>
                                        <option value="2" @selected((string) old('nfse.tipo_retencao_iss', setting('nfse.tipo_retencao_iss', 1)) === '2')>{{ trans('nfse::general.settings.issqn_special.withholding_customer') }}</option>
                                        <option value="3" @selected((string) old('nfse.tipo_retencao_iss', setting('nfse.tipo_retencao_iss', 1)) === '3')>{{ trans('nfse::general.settings.issqn_special.withholding_intermediary') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="issqn_pais_resultado">{{ trans('nfse::general.settings.issqn_special.result_country') }}</label>
                                    <input id="issqn_pais_resultado" name="nfse[issqn_pais_resultado]" type="text" maxlength="2" class="w-full border rounded px-3 py-2 uppercase" value="{{ old('nfse.issqn_pais_resultado', setting('nfse.issqn_pais_resultado', '')) }}" placeholder="US">
                                    <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.issqn_special.result_country_help') }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="issqn_tipo_imunidade">{{ trans('nfse::general.settings.issqn_special.immunity_type') }}</label>
                                    <select id="issqn_tipo_imunidade" name="nfse[issqn_tipo_imunidade]" class="w-full border rounded px-3 py-2">
                                        <option value="">{{ trans('general.select') }}</option>
                                        @for($value = 1; $value <= 5; $value++)
                                            <option value="{{ $value }}" @selected((string) old('nfse.issqn_tipo_imunidade', setting('nfse.issqn_tipo_imunidade', '')) === (string) $value)>{{ $value }}</option>
                                        @endfor
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="issqn_tipo_suspensao">{{ trans('nfse::general.settings.issqn_special.suspension_type') }}</label>
                                    <select id="issqn_tipo_suspensao" name="nfse[issqn_tipo_suspensao]" class="w-full border rounded px-3 py-2">
                                        <option value="">{{ trans('general.select') }}</option>
                                        <option value="1" @selected((string) old('nfse.issqn_tipo_suspensao', setting('nfse.issqn_tipo_suspensao', '')) === '1')>{{ trans('nfse::general.settings.issqn_special.suspension_judicial') }}</option>
                                        <option value="2" @selected((string) old('nfse.issqn_tipo_suspensao', setting('nfse.issqn_tipo_suspensao', '')) === '2')>{{ trans('nfse::general.settings.issqn_special.suspension_administrative') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="issqn_numero_processo_suspensao">{{ trans('nfse::general.settings.issqn_special.suspension_process') }}</label>
                                    <input id="issqn_numero_processo_suspensao" name="nfse[issqn_numero_processo_suspensao]" type="text" inputmode="numeric" maxlength="30" class="w-full border rounded px-3 py-2" value="{{ old('nfse.issqn_numero_processo_suspensao', setting('nfse.issqn_numero_processo_suspensao', '')) }}">
                                    <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.issqn_special.suspension_process_help') }}</p>
                                </div>
                            </div>
                        </div>

                        <div
                            id="municipal-parameters-panel"
                            data-url="{{ route('nfse.municipal-parameters') }}"
                            class="rounded-md border border-blue-200 bg-blue-50 p-4 space-y-4"
                        >
                            <div>
                                <h4 class="text-sm font-semibold text-blue-900">{{ trans('nfse::general.settings.municipal_parameters.title') }}</h4>
                                <p class="text-xs text-blue-800 mt-1">{{ trans('nfse::general.settings.municipal_parameters.help') }}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="municipal-parameters-service-code">{{ trans('nfse::general.settings.municipal_parameters.service_code') }}</label>
                                    <input id="municipal-parameters-service-code" type="text" inputmode="numeric" class="w-full border rounded px-3 py-2" placeholder="010701">
                                    <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.municipal_parameters.service_code_help') }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="municipal-parameters-competence">{{ trans('nfse::general.settings.municipal_parameters.competence') }}</label>
                                    <input id="municipal-parameters-competence" type="date" class="w-full border rounded px-3 py-2" value="{{ date('Y-m-d') }}">
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <button id="municipal-parameters-query" type="button" class="inline-flex items-center px-4 py-2 rounded bg-blue-700 text-white hover:bg-blue-800">
                                    {{ trans('nfse::general.settings.municipal_parameters.query') }}
                                </button>
                                <span id="municipal-parameters-status" class="text-sm text-gray-600" aria-live="polite"></span>
                            </div>

                            <div id="municipal-parameters-result-wrapper" class="hidden">
                                <p class="text-sm font-medium mb-2">{{ trans('nfse::general.settings.municipal_parameters.result') }}</p>
                                <pre id="municipal-parameters-result" class="overflow-x-auto rounded bg-gray-900 p-3 text-xs text-gray-100 whitespace-pre-wrap"></pre>
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input name="nfse[sandbox_mode]" type="checkbox" value="1" @checked((bool) old('nfse.sandbox_mode', setting('nfse.sandbox_mode', true)))>
                            <span>{{ trans('nfse::general.settings.sandbox_mode') }}</span>
                        </label>

                        <div class="flex justify-end pt-2">
                            <button id="federal-save-button" type="submit" class="inline-flex justify-center items-center px-4 py-2 rounded font-semibold shadow-sm transition-colors duration-150 bg-gray-300 text-gray-500 cursor-not-allowed" disabled aria-disabled="true">
                                {{ trans('general.save') }}
                            </button>
                        </div>

                    </form>
                @endif
            </div>

            {{-- ── Panel 4: Federal taxation ─────────────────────────────── --}}
            <div id="tab-panel-federal" role="tabpanel" aria-labelledby="tab-btn-federal" aria-hidden="{{ $activeTab === 'federal' ? 'false' : 'true' }}" class="tab-panel @if($activeTab !== 'federal') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form method="POST" action="{{ route('nfse.settings.federal') }}" class="space-y-4">
                        @csrf
                        @method('PATCH')

                        <h3 class="text-base font-semibold text-gray-900">{{ trans('nfse::general.settings.federal.heading') }}</h3>

                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                            {{ trans('nfse::general.settings.federal.canonical_source_notice') }}
                        </div>

                        <input name="nfse[tributacao_federal_mode]" type="hidden" value="percentage_profile">

                        <div id="federal-piscofins-panel">
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

                            <div id="federal-piscofins-retention-row">
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

                        <p id="federal-piscofins-preview-note" class="text-xs text-gray-500 mt-1">
                            {{ trans('nfse::general.settings.federal.piscofins_preview_note') }}
                        </p>

                        <div id="federal-valor-csll-row">
                            <label class="block text-sm font-medium mb-1">{{ trans('nfse::general.settings.federal.valor_csll') }}</label>
                            <div class="relative">
                                <input name="nfse[federal_valor_csll]" type="text" data-tax-affix="percent" class="w-full border rounded px-3 py-2 pr-8 federal-piscofins-field" value="{{ old('nfse.federal_valor_csll', setting('nfse.federal_valor_csll', '')) }}" placeholder="0.00">
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 text-sm">%</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.federal.valor_csll_hint') }}</p>
                        </div>

                        </div>{{-- /#federal-piscofins-panel --}}

                        <div id="federal-tributos-profile-p">
                            <label class="block text-sm font-medium mb-1">{{ trans('nfse::general.settings.federal.tributos_fed_p') }}</label>
                            <div class="relative">
                                <input name="nfse[tributos_fed_p]" type="text" data-tax-affix="percent" class="w-full border rounded px-3 py-2 pr-8" value="{{ old('nfse.tributos_fed_p', setting('nfse.tributos_fed_p', '')) }}" placeholder="0.00">
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 text-sm">%</span>
                            </div>
                        </div>

                        <div id="federal-tributos-profile-sn">
                            <label class="block text-sm font-medium mb-1">{{ trans('nfse::general.settings.federal.tributos_mun_sn') }}</label>
                            <div class="relative">
                                <input name="nfse[tributos_mun_sn]" type="text" data-tax-affix="percent" class="w-full border rounded px-3 py-2 pr-8" value="{{ old('nfse.tributos_mun_sn', setting('nfse.tributos_mun_sn', '')) }}" placeholder="0.00">
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 text-sm">%</span>
                            </div>
                        </div>

                        <div id="ibs-cbs-panel" class="border-t border-gray-200 pt-4 space-y-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">{{ trans('nfse::general.settings.federal.ibs_cbs_heading') }}</h4>
                                <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.federal.ibs_cbs_notice') }}</p>
                            </div>

                            <label class="inline-flex items-center gap-2">
                                <input name="nfse[ibs_cbs_enabled]" type="hidden" value="0">
                                <input id="ibs-cbs-enabled" name="nfse[ibs_cbs_enabled]" type="checkbox" value="1" @checked((bool) old('nfse.ibs_cbs_enabled', setting('nfse.ibs_cbs_enabled', false)))>
                                <span>{{ trans('nfse::general.settings.federal.ibs_cbs_enabled') }}</span>
                            </label>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1" for="ibs-cbs-ind-final">{{ trans('nfse::general.settings.federal.ibs_cbs_ind_final') }}</label>
                                    <select id="ibs-cbs-ind-final" name="nfse[ibs_cbs_ind_final]" class="w-full border rounded px-3 py-2">
                                        <option value="">{{ trans('nfse::general.settings.federal.ibs_cbs_ind_final_empty') }}</option>
                                        <option value="0" @selected((string) old('nfse.ibs_cbs_ind_final', setting('nfse.ibs_cbs_ind_final', '')) === '0')>{{ trans('nfse::general.settings.federal.ibs_cbs_ind_final_no') }}</option>
                                        <option value="1" @selected((string) old('nfse.ibs_cbs_ind_final', setting('nfse.ibs_cbs_ind_final', '')) === '1')>{{ trans('nfse::general.settings.federal.ibs_cbs_ind_final_yes') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="ibs-cbs-c-ind-op">{{ trans('nfse::general.settings.federal.ibs_cbs_c_ind_op') }}</label>
                                    <input id="ibs-cbs-c-ind-op" name="nfse[ibs_cbs_c_ind_op]" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="w-full border rounded px-3 py-2" value="{{ old('nfse.ibs_cbs_c_ind_op', setting('nfse.ibs_cbs_c_ind_op', '')) }}" placeholder="000000">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="ibs-cbs-ind-dest">{{ trans('nfse::general.settings.federal.ibs_cbs_ind_dest') }}</label>
                                    <select id="ibs-cbs-ind-dest" name="nfse[ibs_cbs_ind_dest]" class="w-full border rounded px-3 py-2">
                                        <option value="0" @selected((string) old('nfse.ibs_cbs_ind_dest', setting('nfse.ibs_cbs_ind_dest', '0')) === '0')>{{ trans('nfse::general.settings.federal.ibs_cbs_ind_dest_same') }}</option>
                                        <option value="1" @selected((string) old('nfse.ibs_cbs_ind_dest', setting('nfse.ibs_cbs_ind_dest', '0')) === '1')>{{ trans('nfse::general.settings.federal.ibs_cbs_ind_dest_other') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="ibs-cbs-cst">{{ trans('nfse::general.settings.federal.ibs_cbs_cst') }}</label>
                                    <input id="ibs-cbs-cst" name="nfse[ibs_cbs_cst]" type="text" inputmode="numeric" pattern="[0-9]{3}" maxlength="3" class="w-full border rounded px-3 py-2" value="{{ old('nfse.ibs_cbs_cst', setting('nfse.ibs_cbs_cst', '')) }}" placeholder="000">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1" for="ibs-cbs-c-class-trib">{{ trans('nfse::general.settings.federal.ibs_cbs_c_class_trib') }}</label>
                                    <input id="ibs-cbs-c-class-trib" name="nfse[ibs_cbs_c_class_trib]" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="w-full border rounded px-3 py-2" value="{{ old('nfse.ibs_cbs_c_class_trib', setting('nfse.ibs_cbs_c_class_trib', '')) }}" placeholder="000000">
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

            {{-- ── Panel 5: Artifact Storage ────────────────────────────── --}}
            <div id="tab-panel-artifacts" role="tabpanel" aria-labelledby="tab-btn-artifacts" aria-hidden="{{ $activeTab === 'artifacts' ? 'false' : 'true' }}" class="tab-panel @if($activeTab !== 'artifacts') hidden @endif">

                @if(!$hasSavedSettings)
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded">
                        {{ trans('nfse::general.settings.vault_gate_locked_notice') }}
                    </div>
                @else
                    <form id="artifacts-settings-form" method="POST" action="{{ route('nfse.settings.artifacts') }}" class="space-y-4">
                        @csrf
                        @method('PATCH')

                        <h3 class="text-base font-semibold text-gray-900">{{ trans('nfse::general.settings.artifacts.heading') }}</h3>
                        <p class="text-sm text-gray-600">{{ trans('nfse::general.settings.artifacts.helper') }}</p>
                        <p class="text-xs text-gray-600 rounded border border-blue-200 bg-blue-50 px-3 py-2">
                            {{ trans('nfse::general.settings.artifacts.connection_validation_help') }}
                        </p>

                        <div>
                            <label class="block text-sm font-medium mb-1" for="webdav_url">{{ trans('nfse::general.settings.artifacts.webdav_url') }}</label>
                            <input id="webdav_url" name="nfse[webdav_url]" type="url" class="w-full border rounded px-3 py-2" value="{{ old('nfse.webdav_url', setting('nfse.webdav_url', '')) }}" placeholder="https://storage.example.com/dav">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" for="webdav_username">{{ trans('nfse::general.settings.artifacts.webdav_username') }}</label>
                                <input id="webdav_username" name="nfse[webdav_username]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.webdav_username', setting('nfse.webdav_username', '')) }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="webdav_password">{{ trans('nfse::general.settings.artifacts.webdav_password') }}</label>
                                <input id="webdav_password" name="nfse[webdav_password]" type="password" class="w-full border rounded px-3 py-2" autocomplete="new-password" value="{{ old('nfse.webdav_password', setting('nfse.webdav_password', '')) }}">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1" for="webdav_path_template">{{ trans('nfse::general.settings.artifacts.webdav_path_template') }}</label>
                            <input id="webdav_path_template" name="nfse[webdav_path_template]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.webdav_path_template', setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}')) }}">
                            <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.artifacts.webdav_path_template_hint') }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1" for="webdav_filename_template">{{ trans('nfse::general.settings.artifacts.webdav_filename_template') }}</label>
                            <input id="webdav_filename_template" name="nfse[webdav_filename_template]" type="text" class="w-full border rounded px-3 py-2" value="{{ old('nfse.webdav_filename_template', setting('nfse.webdav_filename_template', '{chave_acesso}')) }}">
                            <p class="text-xs text-gray-500 mt-1">{{ trans('nfse::general.settings.artifacts.webdav_filename_template_hint') }}</p>
                        </div>

                        <div class="rounded border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3">{{ trans('nfse::general.settings.artifacts.available_placeholders') }}</h4>
                            <ul class="space-y-1">
                                @foreach(trans('nfse::general.settings.artifacts.placeholders') as $placeholder => $description)
                                    <li class="text-sm text-gray-700"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs font-mono">{{ $placeholder }}</code> — {{ $description }}</li>
                                @endforeach
                            </ul>
                        </div>

                        @php
                            $storeXmlOn = (bool) old('nfse.webdav_store_xml', setting('nfse.webdav_store_xml', true));
                            $storePdfOn = (bool) old('nfse.webdav_store_pdf', setting('nfse.webdav_store_pdf', true));
                            $canSaveArtifacts = $storeXmlOn || $storePdfOn;
                        @endphp
                        <div class="space-y-1 rounded border border-gray-200 bg-gray-50 p-3">
                            <div class="flex items-center justify-between py-1">
                                <span class="text-sm text-gray-700">{{ trans('nfse::general.settings.artifacts.store_xml') }}</span>
                                <div class="flex items-center">
                                    <button
                                        type="button"
                                        id="webdav_store_xml_yes"
                                        class="relative w-10 rounded-tl-lg rounded-bl-lg py-2 px-1 text-sm text-center transition-all {{ $storeXmlOn ? 'bg-green-500 text-white' : 'bg-black-100' }}"
                                        onclick="(function(){var input=document.getElementById('webdav_store_xml_val');var yes=document.getElementById('webdav_store_xml_yes');var no=document.getElementById('webdav_store_xml_no');input.value='1';yes.classList.add('bg-green-500','text-white');yes.classList.remove('bg-black-100');no.classList.add('bg-black-100');no.classList.remove('bg-red-500','text-white');window.nfseSyncArtifactSaveState&&window.nfseSyncArtifactSaveState();})()"
                                    >{{ trans('general.yes') }}</button>
                                    <button
                                        type="button"
                                        id="webdav_store_xml_no"
                                        class="relative w-10 rounded-tr-lg rounded-br-lg py-2 px-1 text-sm text-center transition-all {{ $storeXmlOn ? 'bg-black-100' : 'bg-red-500 text-white' }}"
                                        onclick="(function(){var input=document.getElementById('webdav_store_xml_val');var yes=document.getElementById('webdav_store_xml_yes');var no=document.getElementById('webdav_store_xml_no');input.value='0';no.classList.add('bg-red-500','text-white');no.classList.remove('bg-black-100');yes.classList.add('bg-black-100');yes.classList.remove('bg-green-500','text-white');window.nfseSyncArtifactSaveState&&window.nfseSyncArtifactSaveState();})()"
                                    >{{ trans('general.no') }}</button>
                                </div>
                                <input type="hidden" id="webdav_store_xml_val" name="nfse[webdav_store_xml]" value="{{ $storeXmlOn ? '1' : '0' }}">
                            </div>
                            <div class="flex items-center justify-between py-1">
                                <span class="text-sm text-gray-700">{{ trans('nfse::general.settings.artifacts.store_pdf') }}</span>
                                <div class="flex items-center">
                                    <button
                                        type="button"
                                        id="webdav_store_pdf_yes"
                                        class="relative w-10 rounded-tl-lg rounded-bl-lg py-2 px-1 text-sm text-center transition-all {{ $storePdfOn ? 'bg-green-500 text-white' : 'bg-black-100' }}"
                                        onclick="(function(){var input=document.getElementById('webdav_store_pdf_val');var yes=document.getElementById('webdav_store_pdf_yes');var no=document.getElementById('webdav_store_pdf_no');input.value='1';yes.classList.add('bg-green-500','text-white');yes.classList.remove('bg-black-100');no.classList.add('bg-black-100');no.classList.remove('bg-red-500','text-white');window.nfseSyncArtifactSaveState&&window.nfseSyncArtifactSaveState();})()"
                                    >{{ trans('general.yes') }}</button>
                                    <button
                                        type="button"
                                        id="webdav_store_pdf_no"
                                        class="relative w-10 rounded-tr-lg rounded-br-lg py-2 px-1 text-sm text-center transition-all {{ $storePdfOn ? 'bg-black-100' : 'bg-red-500 text-white' }}"
                                        onclick="(function(){var input=document.getElementById('webdav_store_pdf_val');var yes=document.getElementById('webdav_store_pdf_yes');var no=document.getElementById('webdav_store_pdf_no');input.value='0';no.classList.add('bg-red-500','text-white');no.classList.remove('bg-black-100');yes.classList.add('bg-black-100');yes.classList.remove('bg-green-500','text-white');window.nfseSyncArtifactSaveState&&window.nfseSyncArtifactSaveState();})()"
                                    >{{ trans('general.no') }}</button>
                                </div>
                                <input type="hidden" id="webdav_store_pdf_val" name="nfse[webdav_store_pdf]" value="{{ $storePdfOn ? '1' : '0' }}">
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button
                                id="artifacts-save-button"
                                type="submit"
                                class="inline-flex items-center px-4 py-2 rounded bg-green-600 text-white {{ $canSaveArtifacts ? 'hover:bg-green-700' : 'opacity-50 cursor-not-allowed' }}"
                                @disabled(!$canSaveArtifacts)
                            >
                                {{ trans('general.save') }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>

        </div>

        @php
            $nfseSettingsFrontendConfig = [
                'parsePfxUrl' => route('nfse.certificate.parse'),
                'hasSavedSettings' => ($certificateState['has_saved_settings'] ?? false) === true,
                'confirmDeleteCertificate' => trans('nfse::general.confirm_delete_certificate_and_settings'),
                'certificateLabel' => trans('nfse::general.settings.certificate'),
                'invalidPfx' => trans('nfse::general.invalid_pfx'),
                'cnpjNotFound' => trans('nfse::general.cnpj_not_found'),
                'selectedOpcaoSimplesNacional' => old('nfse.opcao_simples_nacional', setting('nfse.opcao_simples_nacional', 2)),
                'selectedUf' => old('nfse.uf', setting('nfse.uf', '')),
                'selectedMunicipalityName' => old('nfse.municipio_nome', setting('nfse.municipio_nome', '')),
                'selectedIbge' => old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '')),
                'ufsUrl' => route('nfse.ibge.ufs'),
                'municipalitiesUrlTemplate' => route('nfse.ibge.municipalities', ['uf' => '__UF__']),
                'municipalInvalidService' => trans('nfse::general.settings.municipal_parameters.invalid_service'),
                'municipalQuerying' => trans('nfse::general.settings.municipal_parameters.querying'),
                'municipalQueryFailed' => trans('nfse::general.settings.municipal_parameters.query_failed'),
                'municipalCachedWarning' => trans('nfse::general.settings.municipal_parameters.cached_warning'),
                'municipalLiveSource' => trans('nfse::general.settings.municipal_parameters.live_source'),
            ];
        @endphp
        <script id="nfse-settings-config" type="application/json">
            @json($nfseSettingsFrontendConfig)
        </script>
        <script
            src="{{ asset('modules/Nfse/Resources/assets/js/settings-ui.js?v=' . module_version('nfse')) }}"
            data-nfse-settings-module="true"
        ></script>
    </x-slot>
</x-layouts.admin>
