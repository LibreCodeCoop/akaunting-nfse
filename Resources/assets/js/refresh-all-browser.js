// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseRefreshAllBrowser = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function init(documentRef, fetchImpl, reload) {
        const form = documentRef?.getElementById('refresh-all-form');
        const button = documentRef?.getElementById('index-more-actions-refresh-nfse-invoices');
        const feedback = documentRef?.getElementById('nfse-refresh-all-feedback');
        if (!form || !button || !feedback || form.dataset.booted === 'true') return;
        form.dataset.booted = 'true';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (button.disabled) return;

            const oldLabel = button.textContent;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = form.dataset.loadingLabel || oldLabel;
            feedback.textContent = '';

            try {
                const response = await fetchImpl(form.dataset.refreshUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.dataset.csrfToken,
                    },
                    body: '{}',
                });
                const data = await response.json().catch(() => ({}));
                feedback.textContent = typeof data.message === 'string'
                    ? data.message : form.dataset.errorMessage;
                if (!response.ok) {
                    feedback.focus();
                    return;
                }
                if (data.success === true) {
                    reload();
                } else {
                    feedback.focus();
                }
            } catch (_error) {
                feedback.textContent = form.dataset.errorMessage;
                feedback.focus();
            } finally {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.textContent = oldLabel;
            }
        });
    }

    function boot(documentRef, fetchImpl) {
        if (!documentRef) return;
        const run = () => init(documentRef, fetchImpl, () => globalThis.location?.reload?.());
        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    }

    return { init, boot };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined' && globalThis.NfseRefreshAllBrowser) {
    globalThis.NfseRefreshAllBrowser.boot(document, fetch);
}
