// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseCompanyAdmin = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const fieldLabels = {
        profile_status: 'Cadastro ou limpeza de certificado interrompida',
        certificate_validity: 'Validade do certificado',
        uf: 'UF do prestador',
        municipio_ibge: 'Município IBGE',
        opcao_simples_nacional: 'Enquadramento do Simples Nacional',
        regime_apuracao_simples_nacional: 'Regime de apuração do Simples',
        regime_especial_tributacao: 'Regime especial de tributação',
        tributacao_issqn: 'Tributação do ISSQN',
        tipo_retencao_iss: 'Retenção de ISSQN',
    };

    const reasonLabels = {
        not_checked: 'Ainda não verificado',
        missing_configuration: 'Configure o endereço, mount e autenticação.',
        configuration_unreadable: 'Credenciais criptografadas ilegíveis; reconfigure a infraestrutura.',
        invalid_endpoint: 'Informe uma URL HTTPS válida sem credenciais na URL.',
        invalid_mount: 'Confira o caminho do mount KV v2.',
        server_unavailable: 'Servidor indisponível ou selado.',
        authentication_failed: 'Falha de autenticação. Revise o Token ou AppRole.',
        kv_v2_unavailable: 'Mount KV v2 não acessível.',
        permission_check_failed: 'Falha na consulta de permissões de certificados.',
        insufficient_capabilities: 'Conceda leitura, criação e atualização de certificados.',
        network_failure: 'Falha de rede; confira o endereço do servidor.',
        verified: 'Diagnóstico genérico positivo; o backend verifica cada caminho real ao cadastrar.',
    };

    // Mirror the emission-domain restrictions without silently changing a saved
    // fiscal choice. An invalid selection remains visible for operator review.
    function specialRegimeUiState(simples, issqn, selected, apuration = '') {
        const option = String(simples);
        const taxation = String(issqn);
        const current = String(selected);
        const apurationCode = String(apuration);
        const isMeEpp = option === '3';
        const missingSimples = !['1', '2', '3'].includes(option);
        const missingApuration = isMeEpp && !['1', '2', '3'].includes(apurationCode);
        const canSelectSpecial = taxation === '1'
            && (option === '1' || (isMeEpp && ['2', '3'].includes(apurationCode)));

        return {
            canSelectSpecial,
            hasIncompatibleSelection: !canSelectSpecial && current !== '' && current !== '0',
            requiresRuntimeReview: missingApuration,
            reason: missingSimples ? 'simples_unknown' : missingApuration ? 'sn_apuration_missing'
                : isMeEpp && apurationCode === '1' ? 'sn_requires_none'
                    : taxation !== '1' ? 'issqn' : option === '2' ? 'mei' : 'available',
        };
    }

    function visibleEligible(snapshot) {
        return Array.isArray(snapshot?.eligible_companies) ? snapshot.eligible_companies : [];
    }

    function showAdd(snapshot, canUpdate) {
        return Boolean(canUpdate) && visibleEligible(snapshot).length > 0;
    }

    function canCreate(snapshot, vaultState, canUpdate) {
        return showAdd(snapshot, canUpdate) && vaultState === 'operational';
    }

    function pendingLabels(profile) {
        const pending = Array.isArray(profile?.fiscal_readiness?.pending)
            ? profile.fiscal_readiness.pending : [];
        return pending.map((key) => fieldLabels[key] || 'Revisão fiscal pendente');
    }

    function errorMessage(status, payload) {
        if ([401, 403, 404].includes(status)) {
            return 'Acesso não autorizado à configuração NFS-e.';
        }
        const errors = payload && typeof payload.errors === 'object' && payload.errors !== null
            ? Object.values(payload.errors).flat().filter((value) => typeof value === 'string') : [];
        return errors[0] || (typeof payload?.message === 'string' ? payload.message : '')
            || (typeof payload?.reason === 'string' ? payload.reason : '')
            || 'Operação não concluída. Tente novamente.';
    }

    function init(documentRef, config, fetchImpl, confirmImpl) {
        if (!documentRef?.getElementById('nfse-company-admin')) return;

        const el = (id) => documentRef.getElementById(id);
        const form = el('profile-form');
        const companySelect = el('profile-company');
        const addButton = el('profile-add');
        const editor = el('profile-editor');
        let snapshot = { profiles: [], eligible_companies: [] };
        let loaded = false;
        let vaultState = 'unverified';
        let editingId = null;
        let saving = false;

        const message = (node, value) => {
            if (!node) return;
            node.textContent = value || '';
            node.hidden = !value;
        };
        const urlFor = (pattern, id) => pattern.replace('__COMPANY__', encodeURIComponent(String(id)));

        async function requestJson(url, method = 'GET', body) {
            const response = await fetchImpl(url, {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrfToken },
                ...(body ? { body } : {}),
            });
            const payload = response.status === 204 ? null : await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(errorMessage(response.status, payload));
            return payload;
        }

        function setVaultEditorVisibility(open) {
            if (!el('vault-editor')) return;
            el('vault-editor').hidden = !open;
            el('vault-edit')?.setAttribute('aria-expanded', String(open));
        }

        function syncAuthMode() {
            const roleMode = el('auth-mode-approle')?.checked === true;
            el('vault-token-section').hidden = roleMode;
            el('vault-approle-section').hidden = !roleMode;
            for (const name of ['bao_token', 'bao_role_id', 'bao_secret_id']) {
                const input = el(name);
                if (input) input.disabled = roleMode ? name === 'bao_token' : name !== 'bao_token';
            }
        }

        function syncFiscalRegime() {
            const simples = el('profile-simples');
            const issqn = el('profile-issqn');
            const apuration = el('profile-apuracao');
            const regime = el('profile-regime');
            const help = el('profile-regime-help');
            if (!simples || !issqn || !apuration || !regime || !help) return;
            const isMeEpp = simples.value === '3';
            apuration.disabled = !isMeEpp;
            apuration.closest('label').hidden = !isMeEpp;
            const state = specialRegimeUiState(simples.value, issqn.value, regime.value, apuration.value);
            regime.setAttribute('aria-invalid', String(state.hasIncompatibleSelection));
            for (const option of regime.options) {
                if (option.value !== '' && option.value !== '0') {
                    option.disabled = !state.canSelectSpecial;
                }
            }
            help.textContent = state.hasIncompatibleSelection
                ? 'Regime incompatível com Simples, apuração ou tributação ISSQN. Revise antes de emitir.'
                : state.requiresRuntimeReview
                    ? 'ME/EPP exige escolher explicitamente o regime de apuração do Simples.'
                    : !state.canSelectSpecial
                        ? 'Regimes especiais dependem do enquadramento do prestador e da tributação ISSQN.'
                        : 'Regimes municipais especiais exigem verificação específica antes da emissão.';
        }

        function openEditor(profile) {
            message(el('profile-form-error'), '');
            form.reset();
            editingId = profile ? Number(profile.company_id) : null;
            companySelect.replaceChildren();
            const entries = profile
                ? [{ company_id: profile.company_id, name: profile.name }]
                : visibleEligible(snapshot);
            for (const company of entries) {
                const option = documentRef.createElement('option');
                option.value = String(company.company_id);
                option.textContent = company.name;
                companySelect.appendChild(option);
            }
            companySelect.disabled = Boolean(profile);
            el('profile-editor-title').textContent = profile ? 'Editar configuração NFS-e' : 'Adicionar configuração NFS-e';
            el('profile-rotation-hint').hidden = !profile;
            el('profile-pfx').required = !profile;
            el('profile-pfx-password').required = !profile;
            const fields = profile?.fiscal_parameters || {};
            for (const input of form.querySelectorAll('[name^="fiscal_parameters["]')) {
                if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement)) continue;
                const name = input.name.slice('fiscal_parameters['.length, -1);
                const value = fields[name];
                if (value !== undefined && value !== null) input.value = String(value);
            }
            editor.hidden = false;
            syncFiscalRegime();
            el('profile-editor-title').focus();
        }

        function closeEditor() {
            editor.hidden = true;
            editingId = null;
            form.reset();
            message(el('profile-form-error'), '');
        }

        function profileCell(row, value) {
            const td = documentRef.createElement('td');
            td.className = 'px-4 py-3 align-top';
            td.textContent = value;
            row.appendChild(td);
            return td;
        }

        function addRowAction(cell, title, action, destructive = false) {
            const button = documentRef.createElement('button');
            button.type = 'button';
            button.className = 'ml-3 underline ' + (destructive ? 'text-red-700' : 'text-indigo-700');
            button.textContent = title;
            button.addEventListener('click', action);
            cell.appendChild(button);
        }

        function renderProfiles() {
            if (!loaded) return;
            const profiles = Array.isArray(snapshot.profiles) ? snapshot.profiles : [];
            const eligible = visibleEligible(snapshot);
            el('profiles-loading').hidden = true;
            el('profiles-table-wrap').hidden = profiles.length === 0;
            el('profiles-empty').hidden = profiles.length > 0;
            el('profiles-body').replaceChildren();

            if (profiles.length === 0) {
                message(el('profiles-empty'), eligible.length
                    ? 'Nenhuma empresa possui configuração NFS-e. Selecione Adicionar para começar.'
                    : 'Nenhuma empresa disponível. Cadastre uma empresa no Akaunting ou solicite acesso às empresas existentes.');
            }
            for (const profile of profiles) {
                const row = documentRef.createElement('tr');
                row.dataset.companyId = String(profile.company_id);
                profileCell(row, profile.name || 'Empresa #' + profile.company_id);
                const readiness = profile.fiscal_readiness || {};
                const pending = pendingLabels(profile);
                const status = profile.status !== 'active'
                    ? 'Recuperação necessária'
                    : pending.length ? 'Pendências: ' + pending.join(', ')
                        : readiness.basic_complete ? 'Dados básicos preenchidos; sujeito ao preflight fiscal' : 'Revisão fiscal pendente';
                const statusCell = profileCell(row, status);
                statusCell.dataset.readiness = pending.length ? 'pending' : 'basic';
                if (readiness.municipal_review_required) {
                    const tip = documentRef.createElement('p');
                    tip.className = 'mt-1 text-xs text-amber-800';
                    tip.textContent = 'Validação municipal específica ainda necessária.';
                    statusCell.appendChild(tip);
                }
                const actions = profileCell(row, '');
                actions.className += ' text-right whitespace-nowrap';
                if (config.canUpdate && profile.status === 'active' && !profile.cleanup_pending) {
                    addRowAction(actions, 'Editar', () => openEditor(profile));
                }
                if (config.canDelete && (profile.cleanup_pending || profile.status !== 'active')) {
                    addRowAction(actions, 'Retomar limpeza', () => cleanup(profile));
                }
                if (config.canDelete && profile.status === 'active') {
                    addRowAction(actions, 'Excluir', () => destroy(profile), true);
                }
                el('profiles-body').appendChild(row);
            }

            if (addButton) {
                addButton.hidden = !showAdd(snapshot, config.canUpdate);
                addButton.disabled = !canCreate(snapshot, vaultState, config.canUpdate);
                addButton.setAttribute('aria-describedby', 'profile-add-hint');
            }
            let hint = '';
            if (eligible.length && config.canUpdate && vaultState !== 'operational') {
                hint = 'Configure ou corrija a infraestrutura e conclua a verificação do Vault antes de adicionar uma empresa. Perfis existentes continuam disponíveis.';
            }
            message(el('profile-add-hint'), hint);
        }

        async function refreshProfiles() {
            try {
                const data = await requestJson(config.listUrl);
                snapshot = {
                    profiles: Array.isArray(data.profiles) ? data.profiles : [],
                    eligible_companies: visibleEligible(data),
                };
                loaded = true;
                message(el('profiles-error'), '');
                renderProfiles();
            } catch (error) {
                // On access errors never preserve company metadata from an earlier response.
                loaded = false;
                snapshot = { profiles: [], eligible_companies: [] };
                closeEditor();
                el('profiles-loading').hidden = true;
                el('profiles-table-wrap').hidden = true;
                el('profiles-empty').hidden = true;
                if (addButton) addButton.hidden = true;
                message(el('profile-add-hint'), '');
                message(el('profiles-error'), error.message);
            }
        }

        async function checkVault() {
            vaultState = 'unverified';
            el('vault-status').textContent = 'Verificando infraestrutura...';
            renderProfiles();
            el('vault-check').disabled = true;
            try {
                const diag = await requestJson(config.diagnosticsUrl, 'POST');
                vaultState = ['operational', 'unconfigured', 'failed'].includes(diag.state) ? diag.state : 'failed';
                el('vault-status').textContent = vaultState === 'operational' ? 'Operacional'
                    : vaultState === 'unconfigured' ? 'Não configurado' : 'Falha na infraestrutura';
                el('vault-reason').textContent = reasonLabels[diag.reason] || 'Verificação inconclusiva. Revise o diagnóstico.';
                const capabilities = diag.capabilities || {};
                const labels = {
                    network: 'Conexão',
                    authentication: 'Autenticação',
                    kv_v2: 'KV v2',
                    certificate_read: 'Leitura de certificados',
                    certificate_write: 'Criação e atualização de certificados',
                };
                el('vault-capabilities').replaceChildren();
                for (const [key, label] of Object.entries(labels)) {
                    const li = documentRef.createElement('li');
                    li.textContent = label + ': ' + (capabilities[key] === true ? 'OK' : 'Não verificado');
                    el('vault-capabilities').appendChild(li);
                }
            } catch (error) {
                vaultState = 'failed';
                el('vault-status').textContent = 'Falha no diagnóstico';
                el('vault-reason').textContent = error.message;
                el('vault-capabilities').replaceChildren();
            } finally {
                el('vault-last-checked').textContent = 'Verificado em ' + new Date().toLocaleString('pt-BR');
                el('vault-check').disabled = false;
                renderProfiles();
            }
        }

        async function destroy(profile) {
            if (!confirmImpl('Excluir a configuração NFS-e de "' + profile.name + '"? A empresa do Akaunting e os documentos fiscais históricos não serão excluídos.')) return;
            try {
                await requestJson(urlFor(config.deleteUrl, profile.company_id), 'DELETE');
                closeEditor();
                message(el('profile-feedback'), 'Configuração excluída. A empresa poderá ser cadastrada novamente.');
                await refreshProfiles();
            } catch (error) {
                message(el('profile-feedback'), 'Exclusão não concluída: ' + error.message);
                await refreshProfiles();
            }
        }

        async function cleanup(profile) {
            try {
                await requestJson(urlFor(config.cleanupUrl, profile.company_id), 'POST');
                message(el('profile-feedback'), 'Limpeza concluída. Estado da empresa atualizado.');
                await refreshProfiles();
            } catch (error) {
                message(el('profile-feedback'), 'Limpeza não concluída: ' + error.message);
                await refreshProfiles();
            }
        }

        async function submitProfile(event) {
            event.preventDefault();
            if (saving) return;
            if (editingId === null && !canCreate(snapshot, vaultState, config.canUpdate)) {
                message(el('profile-form-error'), 'Verifique o Vault e a elegibilidade da empresa antes de cadastrar.');
                return;
            }
            const data = new FormData(form);
            const file = el('profile-pfx').files[0];
            if (!file) {
                data.delete('pfx_file');
                data.delete('pfx_password');
            } else if (vaultState !== 'operational') {
                message(el('profile-form-error'), 'Verifique a infraestrutura antes de enviar ou rotacionar certificado.');
                return;
            }
            if (editingId !== null) {
                data.delete('company_id');
                data.set('_method', 'PATCH'); // PHP parses multipart POST, Laravel honors method override.
            }
            saving = true;
            el('profile-save').disabled = true;
            message(el('profile-form-error'), '');
            try {
                const path = editingId === null ? config.createUrl : urlFor(config.updateUrl, editingId);
                await requestJson(path, 'POST', data);
                closeEditor();
                message(el('profile-feedback'), 'Configuração salva. Confira a prontidão fiscal e as pendências na lista.');
                await refreshProfiles();
            } catch (error) {
                message(el('profile-form-error'), error.message);
            } finally {
                saving = false;
                el('profile-save').disabled = false;
            }
        }

        el('profile-add')?.addEventListener('click', () => {
            if (canCreate(snapshot, vaultState, config.canUpdate)) openEditor(null);
        });
        el('profile-cancel')?.addEventListener('click', closeEditor);
        el('profile-cancel-bottom')?.addEventListener('click', closeEditor);
        form.addEventListener('submit', submitProfile);
        for (const name of ['profile-simples', 'profile-issqn', 'profile-apuracao', 'profile-regime']) {
            el(name)?.addEventListener('change', syncFiscalRegime);
        }
        el('vault-edit')?.addEventListener('click', () => setVaultEditorVisibility(el('vault-editor').hidden));
        el('vault-check').addEventListener('click', checkVault);
        documentRef.querySelectorAll('input[name="auth_mode_ui"]').forEach((input) => input.addEventListener('change', syncAuthMode));
        if (el('auth-mode-token')) syncAuthMode();
        // Profile listing never waits for the Vault health check.
        void refreshProfiles();
        void checkVault();
    }

    function boot(documentRef, fetchImpl, confirmImpl) {
        if (!documentRef) return;
        const run = () => {
            let config = {};
            try {
                config = JSON.parse(documentRef.getElementById('nfse-company-admin-config')?.textContent || '{}');
            } catch { config = {}; }
            init(documentRef, config, fetchImpl, confirmImpl);
        };
        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    }

    return { visibleEligible, showAdd, canCreate, pendingLabels, errorMessage, specialRegimeUiState, init, boot };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined' && globalThis.NfseCompanyAdmin) {
    globalThis.NfseCompanyAdmin.boot(
        document,
        fetch,
        typeof confirm === 'function' ? confirm : () => false,
    );
}
