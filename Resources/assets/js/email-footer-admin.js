// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseEmailFooterAdmin = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function init(documentRef, fetchImpl) {
        const form = documentRef?.getElementById('nfse-email-footer-form');
        if (!form || form.dataset.booted === 'true') return;
        form.dataset.booted = 'true';

        const message = documentRef.getElementById('nfse-email-footer-message');
        const url = documentRef.getElementById('nfse-email-footer-url');
        const feedback = documentRef.getElementById('nfse-email-footer-feedback');
        const save = documentRef.getElementById('nfse-email-footer-save');
        if (!message || !url || !save || !feedback) return;
        save.disabled = true;

        function showFeedback(value) {
            feedback.textContent = value;
            feedback.hidden = !value;
        }

        function display(data) {
            if (!data || typeof data.footer_message !== 'string' || typeof data.footer_url !== 'string') {
                throw new Error('Invalid email footer response');
            }
            message.value = data.footer_message;
            url.value = data.footer_url;
        }

        async function request(method, body) {
            const response = await fetchImpl(method === 'GET' ? form.dataset.readUrl : form.dataset.writeUrl, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(method !== 'GET' ? {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.dataset.csrfToken,
                    } : {}),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.data) {
                throw new Error('Email footer API request failed');
            }
            return payload.data;
        }

        async function load() {
            try {
                display(await request('GET'));
                save.disabled = false;
            } catch (_error) {
                showFeedback(form.dataset.errorMessage);
                feedback.focus();
            }
        }

        async function submit(event) {
            event.preventDefault();
            if (save.disabled) return;
            save.disabled = true;
            showFeedback('');
            try {
                display(await request('PATCH', {
                    footer_message: message.value,
                    footer_url: url.value,
                }));
                showFeedback(form.dataset.savedMessage);
            } catch (_error) {
                showFeedback(form.dataset.errorMessage);
                feedback.focus();
            } finally {
                save.disabled = false;
            }
        }

        form.addEventListener('submit', submit);
        void load();
    }

    function boot(documentRef, fetchImpl) {
        if (!documentRef) return;
        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', () => init(documentRef, fetchImpl), { once: true });
        } else {
            init(documentRef, fetchImpl);
        }
    }

    return { init, boot };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined' && globalThis.NfseEmailFooterAdmin) {
    globalThis.NfseEmailFooterAdmin.boot(document, fetch);
}
