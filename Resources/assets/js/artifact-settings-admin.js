// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseArtifactSettingsAdmin = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function buildPatch(fields) {
        const nfse = {
            webdav_store_xml: Boolean(fields.storeXml),
            webdav_store_pdf: Boolean(fields.storePdf),
            webdav_path_template: String(fields.pathTemplate || '').trim(),
            webdav_filename_template: String(fields.filenameTemplate || '').trim(),
        };
        if (fields.disable) {
            nfse.webdav_url = '';
        } else {
            for (const [field, value] of [
                ['webdav_url', fields.url],
                ['webdav_username', fields.username],
                ['webdav_password', fields.password],
            ]) {
                if (typeof value === 'string' && value.trim() !== '') {
                    nfse[field] = value.trim();
                }
            }
        }
        return { nfse };
    }

    function init(documentRef, fetchImpl) {
        const root = documentRef?.getElementById('nfse-artifact-settings');
        if (!root || root.dataset.booted === 'true') return;
        root.dataset.booted = 'true';

        const el = (id) => documentRef.getElementById(id);
        const form = el('nfse-artifact-settings-form');
        const summary = el('nfse-artifact-settings-summary');
        const feedback = el('nfse-artifact-settings-feedback');
        const save = el('nfse-webdav-save');

        function message(value) {
            if (!feedback) return;
            feedback.textContent = value;
            feedback.hidden = value === '';
        }

        async function requestJson(url, method = 'GET', body) {
            const response = await fetchImpl(url, {
                method, credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(method !== 'GET' ? {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': root.dataset.csrfToken,
                    } : {}),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if ([401, 403, 404].includes(response.status)) {
                    throw new Error('Acesso negado ao armazenamento de artefatos.');
                }
                throw new Error(data?.reason === 'webdav_connection_failed'
                    ? 'Não foi possível verificar a conexão WebDAV.'
                    : 'Não foi possível validar ou salvar o armazenamento WebDAV.');
            }
            return data?.data;
        }

        function render(data) {
            if (!data || typeof data.configured !== 'boolean') {
                throw new Error('Resposta inválida do servidor.');
            }
            const host = typeof data.host === 'string' ? data.host : '';
            summary.textContent = data.configured
                ? 'WebDAV configurado: ' + host
                : 'WebDAV não configurado';
            if (!form) return;
            el('nfse-webdav-path').value = String(data.path_template || '');
            el('nfse-webdav-filename').value = String(data.filename_template || '');
            el('nfse-webdav-xml').checked = data.store_xml === true;
            el('nfse-webdav-pdf').checked = data.store_pdf === true;
            el('nfse-webdav-url').value = '';
            el('nfse-webdav-user').value = '';
            el('nfse-webdav-password').value = '';
            el('nfse-webdav-disable').checked = false;
        }

        async function load() {
            try {
                render(await requestJson(root.dataset.readUrl));
                return true;
            } catch (error) {
                summary.textContent = error.message;
                if (save) save.disabled = true;
                return false;
            }
        }

        async function submit(event) {
            event.preventDefault();
            if (!form || !save || save.disabled) return;
            save.disabled = true;
            message('Salvando configuração WebDAV...');
            const patch = buildPatch({
                url: el('nfse-webdav-url').value,
                username: el('nfse-webdav-user').value,
                password: el('nfse-webdav-password').value,
                pathTemplate: el('nfse-webdav-path').value,
                filenameTemplate: el('nfse-webdav-filename').value,
                storeXml: el('nfse-webdav-xml').checked,
                storePdf: el('nfse-webdav-pdf').checked,
                disable: el('nfse-webdav-disable').checked,
            });
            try {
                render(await requestJson(root.dataset.writeUrl, 'PATCH', patch));
                message('Configuração de armazenamento atualizada.');
            } catch (error) {
                message(error.message);
                feedback?.focus();
            } finally {
                save.disabled = false;
            }
        }

        form?.addEventListener('submit', submit);
        void load();
    }

    function boot(documentRef, fetchImpl) {
        if (!documentRef) return;
        const run = () => init(documentRef, fetchImpl);
        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    }

    return { buildPatch, init, boot };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined' && globalThis.NfseArtifactSettingsAdmin) {
    globalThis.NfseArtifactSettingsAdmin.boot(document, fetch);
}
