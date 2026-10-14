{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
@php
    $canUpdateProfiles = (bool) (auth()->user()?->can('update-nfse-settings') ?? false);
    $canDeleteProfiles = (bool) (auth()->user()?->can('delete-nfse-settings') ?? false);
    $vaultConfigured = ($vaultUiState['ready'] ?? false) === true;
    $vaultHost = parse_url((string) ($settings['bao_addr'] ?? ''), PHP_URL_HOST) ?: '—';
    $vaultAuthMode = in_array((string) ($vaultUiState['auth_mode'] ?? ''), ['token', 'approle'], true)
        ? (string) $vaultUiState['auth_mode'] : 'incomplete';
    $showVaultEditor = request()->query('tab') === 'vault' || $errors->any();
@endphp
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.title') }}</x-slot>
    <x-slot name="content">
        <div id="nfse-company-admin" class="max-w-5xl space-y-5">
            <div>
                <h1 class="text-xl font-semibold">{{ trans('nfse::general.settings.title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">A empresa do Akaunting continua sendo o cadastro principal. O perfil NFS-e apenas acrescenta certificado e parâmetros fiscais.</p>
            </div>

            @if(session('success'))
                <p class="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm" role="status">{{ session('success') }}</p>
            @endif
            @if(session('error'))
                <p class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm" role="alert">{{ session('error') }}</p>
            @endif

            <section class="rounded border bg-white" aria-labelledby="nfse-companies-title">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                    <div>
                        <h2 id="nfse-companies-title" class="font-semibold">Empresas NFS-e</h2>
                        <p class="text-xs text-gray-500">Perfis das empresas às quais você tem acesso</p>
                    </div>
                    @if($canUpdateProfiles)
                        <button id="profile-add" type="button" class="rounded bg-green-700 px-3 py-2 text-sm text-white" hidden>Adicionar</button>
                    @endif
                </header>
                <div id="profiles-error" class="mx-4 mt-3 rounded border border-red-300 p-3 text-sm text-red-800" role="alert" hidden></div>
                <p id="profiles-loading" class="px-4 py-4 text-sm text-gray-500" role="status">Carregando empresas...</p>
                <div id="profiles-table-wrap" class="overflow-x-auto" hidden>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Empresa</th>
                                <th class="px-4 py-2 text-left">Prontidão fiscal</th>
                                <th class="px-4 py-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="profiles-body" class="divide-y"></tbody>
                    </table>
                </div>
                <p id="profiles-empty" class="px-4 py-4 text-sm text-gray-600" hidden></p>
                <p id="profile-add-hint" class="border-t px-4 py-3 text-sm text-amber-800" hidden></p>
                <div id="profile-feedback" class="mx-4 mb-4 text-sm" role="status" aria-live="polite" hidden></div>

                <section id="profile-editor" class="border-t bg-gray-50 p-4" aria-labelledby="profile-editor-title" hidden>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 id="profile-editor-title" class="font-semibold" tabindex="-1">Configuração NFS-e</h3>
                        <button id="profile-cancel" type="button" class="text-sm text-gray-600 underline">Fechar</button>
                    </div>
                    <p class="mb-3 text-xs text-gray-600">Os dados devem ser conferidos pelo responsável fiscal. Salvar o cadastro não autoriza emissão.</p>
                    <form id="profile-form" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <label class="block text-sm font-medium" for="profile-company">Empresa no Akaunting
                            <select id="profile-company" name="company_id" class="mt-1 block w-full rounded border px-3 py-2" required></select>
                        </label>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="block text-sm" for="profile-pfx">Certificado PFX/P12
                                <input id="profile-pfx" type="file" name="pfx_file" accept=".pfx,.p12" class="mt-1 block w-full rounded border p-2">
                            </label>
                            <label class="block text-sm" for="profile-pfx-password">Senha do PFX/P12
                                <input id="profile-pfx-password" type="password" name="pfx_password" autocomplete="new-password" class="mt-1 block w-full rounded border p-2">
                            </label>
                        </div>
                        <p id="profile-rotation-hint" class="text-xs text-gray-600" hidden>Deixe o arquivo e a senha vazios para manter o certificado atual. Selecionar um novo arquivo efetua a rotação.</p>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="block text-sm" for="profile-uf">UF do prestador
                                <input id="profile-uf" name="fiscal_parameters[uf]" maxlength="2" pattern="[A-Za-z]{2}" class="mt-1 block w-full rounded border p-2" placeholder="RJ">
                            </label>
                            <label class="block text-sm" for="profile-municipality">Código IBGE do município
                                <input id="profile-municipality" name="fiscal_parameters[municipio_ibge]" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" class="mt-1 block w-full rounded border p-2" placeholder="3304557">
                            </label>
                            <label class="block text-sm" for="profile-municipality-name">Município (nome)
                                <input id="profile-municipality-name" name="fiscal_parameters[municipio_nome]" maxlength="255" class="mt-1 block w-full rounded border p-2">
                            </label>
                            <label class="block text-sm" for="profile-im">Inscrição municipal
                                <input id="profile-im" name="fiscal_parameters[prestador_inscricao_municipal]" maxlength="15" class="mt-1 block w-full rounded border p-2">
                            </label>
                            <label class="block text-sm" for="profile-simples">Enquadramento no Simples Nacional
                                <select id="profile-simples" name="fiscal_parameters[opcao_simples_nacional]" class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione</option>
                                    <option value="1">Não optante</option>
                                    <option value="2">MEI</option>
                                    <option value="3">ME/EPP</option>
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-apuracao">Apuração Simples Nacional (ME/EPP)
                                <select id="profile-apuracao" name="fiscal_parameters[regime_apuracao_simples_nacional]" class="mt-1 block w-full rounded border p-2">
                                    <option value="">Não informada</option>
                                    <option value="1">1 — Federais e ISS no Simples</option>
                                    <option value="2">2 — Federais no Simples, ISS fora</option>
                                    <option value="3">3 — Federais e ISS fora</option>
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-regime">Regime especial de tributação
                                <select id="profile-regime" name="fiscal_parameters[regime_especial_tributacao]" aria-describedby="profile-regime-help" aria-invalid="false" class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione</option>
                                    @foreach (['0' => 'Nenhum (0)', '1' => 'Microempresa municipal', '2' => 'Estimativa', '3' => 'Sociedade de profissionais', '4' => 'Cooperativa', '5' => 'MEI', '6' => 'ME/EPP', '9' => 'Outro regime especial'] as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-issqn">Tributação ISSQN
                                <select id="profile-issqn" name="fiscal_parameters[tributacao_issqn]" class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione</option>
                                    <option value="1">Tributável</option>
                                    <option value="2">Imune</option>
                                    <option value="3">Exportação</option>
                                    <option value="4">Não incidência</option>
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-retencao">Retenção ISSQN
                                <select id="profile-retencao" name="fiscal_parameters[tipo_retencao_iss]" class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione</option>
                                    <option value="1">Não retido</option>
                                    <option value="2">Retido pelo tomador</option>
                                    <option value="3">Retido pelo intermediário</option>
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-policy">Política de emissão
                                <select id="profile-policy" name="fiscal_parameters[emission_policy]" class="mt-1 block w-full rounded border p-2">
                                    <option value="manual">Manual (padrão seguro)</option>
                                    <option value="emit_on_send">Ao enviar a fatura (com preflight)</option>
                                </select>
                            </label>
                            <label class="block text-sm" for="profile-environment">Ambiente
                                <select id="profile-environment" name="fiscal_parameters[sandbox_mode]" class="mt-1 block w-full rounded border p-2">
                                    <option value="1">Homologação</option>
                                    <option value="0">Produção</option>
                                </select>
                            </label>
                        </div>

                        <p id="profile-regime-help" class="text-xs text-amber-800" aria-live="polite"></p>
                        <details class="rounded border bg-white p-3 text-sm">
                            <summary class="cursor-pointer font-medium">ISSQN: exportação, imunidade e suspensão</summary>
                            <p class="mt-2 text-xs text-gray-600">Preencha somente os detalhes aplicáveis à tributação selecionada. Valores registrados são mantidos para revisão e não autorizam emissão.</p>
                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <label class="block" for="profile-establishment-confirmed">Estabelecimento do prestador neste município
                                    <select id="profile-establishment-confirmed" name="fiscal_parameters[provider_establishment_confirmed]" class="mt-1 block w-full rounded border p-2">
                                        <option value="0">Não confirmado</option>
                                        <option value="1">Confirmo a identidade do estabelecimento e município</option>
                                    </select>
                                </label>
                                <label class="block" for="profile-issqn-pais">País do resultado (exportação, exceto BR)
                                    <input id="profile-issqn-pais" name="fiscal_parameters[issqn_pais_resultado]" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US" class="mt-1 block w-full rounded border p-2 uppercase">
                                </label>
                                <label class="block" for="profile-issqn-imunidade">Tipo de imunidade
                                    <select id="profile-issqn-imunidade" name="fiscal_parameters[issqn_tipo_imunidade]" class="mt-1 block w-full rounded border p-2">
                                        <option value="">Não informado</option>
                                        @for($value = 1; $value <= 5; $value++)
                                            <option value="{{ $value }}">{{ $value }}</option>
                                        @endfor
                                    </select>
                                </label>
                                <label class="block" for="profile-issqn-suspensao">Suspensão da exigibilidade
                                    <select id="profile-issqn-suspensao" name="fiscal_parameters[issqn_tipo_suspensao]" class="mt-1 block w-full rounded border p-2">
                                        <option value="">Sem suspensão</option>
                                        <option value="1">Judicial</option>
                                        <option value="2">Administrativa</option>
                                    </select>
                                </label>
                                <label class="block" for="profile-issqn-processo">Número do processo de suspensão
                                    <input id="profile-issqn-processo" name="fiscal_parameters[issqn_numero_processo_suspensao]" inputmode="numeric" maxlength="30" pattern="[0-9]{30}" class="mt-1 block w-full rounded border p-2">
                                </label>
                            </div>
                        </details>
                        <details class="rounded border bg-white p-3 text-sm">
                            <summary class="cursor-pointer font-medium">Complementos fiscais e integrações</summary>
                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                @foreach ([
                                    'prestador_telefone' => ['Telefone', 'tel'],
                                    'prestador_email' => ['E-mail', 'email'],
                                    'federal_piscofins_situacao_tributaria' => ['Situação PIS/Cofins', 'text'],
                                    'federal_piscofins_tipo_retencao' => ['Retenção PIS/Cofins', 'text'],
                                    'ibs_cbs_c_ind_op' => ['IBS/CBS cIndOp (6 dígitos)', 'text'],
                                    'ibs_cbs_cst' => ['IBS/CBS CST (3 dígitos)', 'text'],
                                    'ibs_cbs_c_class_trib' => ['IBS/CBS cClassTrib (6 dígitos)', 'text'],
                                ] as $key => [$label, $type])
                                    <label class="block" for="profile-{{ $key }}">{{ $label }}
                                        <input id="profile-{{ $key }}" type="{{ $type }}" name="fiscal_parameters[{{ $key }}]" class="mt-1 block w-full rounded border p-2">
                                    </label>
                                @endforeach
                                <label class="block" for="profile-ibs-enabled">IBS/CBS
                                    <select id="profile-ibs-enabled" name="fiscal_parameters[ibs_cbs_enabled]" class="mt-1 block w-full rounded border p-2">
                                        <option value="0">Não habilitado</option>
                                        <option value="1">Habilitado (requer validação fiscal)</option>
                                    </select>
                                </label>
                                <label class="block" for="profile-ibs-final">IBS/CBS: consumidor final
                                    <select id="profile-ibs-final" name="fiscal_parameters[ibs_cbs_ind_final]" class="mt-1 block w-full rounded border p-2">
                                        <option value="">Não informado</option>
                                        <option value="0">Não</option>
                                        <option value="1">Sim</option>
                                    </select>
                                </label>
                                <label class="block" for="profile-ibs-dest">IBS/CBS: local de destino
                                    <select id="profile-ibs-dest" name="fiscal_parameters[ibs_cbs_ind_dest]" class="mt-1 block w-full rounded border p-2">
                                        <option value="">Não informado</option>
                                        <option value="0">Mesmo município</option>
                                        <option value="1">Outro município</option>
                                    </select>
                                </label>
                            </div>
                        </details>
                        <p id="profile-form-error" class="text-sm text-red-700" role="alert" hidden></p>
                        <div class="flex justify-end gap-3">
                            <button type="button" id="profile-cancel-bottom" class="rounded border px-4 py-2">Cancelar</button>
                            <button type="submit" id="profile-save" class="rounded bg-green-700 px-4 py-2 text-white">Salvar configuração</button>
                        </div>
                    </form>
                </section>
            </section>

            <section class="rounded border bg-white" aria-labelledby="nfse-vault-title">
                <header class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                    <div>
                        <h2 id="nfse-vault-title" class="font-semibold">Infraestrutura · OpenBao/Vault</h2>
                        <p class="mt-1 text-xs text-gray-600">
                            {{ $vaultHost }} · mount {{ $settings['bao_mount'] ?? '—' }} · {{ $vaultAuthMode === 'approle' ? 'AppRole' : ($vaultAuthMode === 'token' ? 'Token' : 'Autenticação incompleta') }}
                        </p>
                        <p class="mt-2 text-sm"><strong id="vault-status" aria-live="polite">{{ $vaultConfigured ? 'Aguardando verificação' : 'Não configurado' }}</strong>
                            <span id="vault-last-checked" class="ml-2 text-xs text-gray-500">Ainda não verificado nesta página</span>
                        </p>
                    </div>
                    @if($canUpdateProfiles)
                        <button type="button" id="vault-edit" aria-controls="vault-editor" aria-expanded="{{ $showVaultEditor ? 'true' : 'false' }}" class="rounded border px-3 py-2 text-sm">{{ $vaultConfigured ? 'Editar' : 'Configurar' }}</button>
                    @endif
                </header>
                @if($vaultUiState['configuration_error'] ?? false)
                    <p id="vault-configuration-unreadable" class="mx-4 mb-3 rounded border border-red-300 p-3 text-sm text-red-700" role="alert">
                        Não foi possível ler a configuração criptografada da infraestrutura. Informe novamente as credenciais; perfis fiscais existentes não serão apagados.
                    </p>
                @endif
                <details id="vault-diagnostics" class="border-t px-4 py-3 text-sm">
                    <summary class="cursor-pointer font-medium">Diagnóstico</summary>
                    <div class="mt-3 space-y-3">
                        <p id="vault-reason" aria-live="polite">A verificação consulta o servidor, autenticação, KV v2 e permissões de certificado.</p>
                        <ul id="vault-capabilities" class="list-inside list-disc text-gray-700"></ul>
                        <button id="vault-check" type="button" class="rounded border px-3 py-2">Verificar agora</button>
                    </div>
                </details>
                @if($canUpdateProfiles)
                    <div id="vault-editor" class="border-t bg-gray-50 p-4" @unless($showVaultEditor) hidden @endunless>
                        <form method="POST" action="{{ route('nfse.settings.vault') }}" class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <p class="text-xs text-gray-600">As credenciais não são exibidas. Valores vazios preservam segredos apenas se o endereço não mudar.</p>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <label class="block text-sm" for="bao_addr">URL HTTPS
                                    <input id="bao_addr" name="nfse[bao_addr]" type="url" required class="mt-1 block w-full rounded border p-2" value="{{ old('nfse.bao_addr', $settings['bao_addr'] ?? '') }}">
                                </label>
                                <label class="block text-sm" for="bao_mount">Mount KV v2
                                    <input id="bao_mount" name="nfse[bao_mount]" required class="mt-1 block w-full rounded border p-2" value="{{ old('nfse.bao_mount', $settings['bao_mount'] ?? '/nfse') }}">
                                </label>
                            </div>
                            <fieldset class="rounded border bg-white p-3 text-sm">
                                <legend class="font-medium">Autenticação</legend>
                                <label class="mr-4"><input id="auth-mode-token" type="radio" name="auth_mode_ui" value="token" @checked(old('auth_mode_ui', $vaultAuthMode) !== 'approle')> Token</label>
                                <label><input id="auth-mode-approle" type="radio" name="auth_mode_ui" value="approle" @checked(old('auth_mode_ui', $vaultAuthMode) === 'approle')> AppRole</label>
                                <div id="vault-token-section" class="mt-3">
                                    <label class="block" for="bao_token">Novo token (deixe vazio para preservar)
                                        <input id="bao_token" name="nfse[bao_token]" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border p-2">
                                    </label>
                                    <label class="mt-2 block"><input name="nfse[clear_bao_token]" type="checkbox" value="1"> Limpar token salvo</label>
                                </div>
                                <div id="vault-approle-section" class="mt-3" hidden>
                                    <label class="block" for="bao_role_id">Role ID
                                        <input id="bao_role_id" name="nfse[bao_role_id]" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border p-2">
                                    </label>
                                    <label class="mt-2 block" for="bao_secret_id">Secret ID
                                        <input id="bao_secret_id" name="nfse[bao_secret_id]" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border p-2">
                                    </label>
                                    <label class="mt-2 block"><input name="nfse[clear_bao_secret_id]" type="checkbox" value="1"> Limpar Secret ID salvo</label>
                                </div>
                            </fieldset>
                            <button class="rounded bg-green-700 px-4 py-2 text-sm text-white" type="submit">Salvar infraestrutura</button>
                        </form>
                    </div>
                @endif
            </section>
        </div>

        @php
            $nfseCompanyAdminConfig = [
                'listUrl' => route('nfse.settings.company-profiles.index'),
                'createUrl' => route('nfse.settings.company-profiles.store'),
                'updateUrl' => route('nfse.settings.company-profiles.update', ['companyId' => '__COMPANY__']),
                'deleteUrl' => route('nfse.settings.company-profiles.destroy', ['companyId' => '__COMPANY__']),
                'cleanupUrl' => route('nfse.settings.company-profiles.cleanup', ['companyId' => '__COMPANY__']),
                'diagnosticsUrl' => route('nfse.settings.vault.diagnostics'),
                'csrfToken' => csrf_token(),
                'canUpdate' => $canUpdateProfiles,
                'canDelete' => $canDeleteProfiles,
            ];
        @endphp
        <script id="nfse-company-admin-config" type="application/json">
            @json($nfseCompanyAdminConfig)
        </script>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/company-profile-admin.js?v=' . module_version('nfse')) }}" data-nfse-company-admin="true"></script>
    </x-slot>
</x-layouts.admin>
