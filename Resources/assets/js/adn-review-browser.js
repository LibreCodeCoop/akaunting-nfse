// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.NfseAdnReviewBrowser = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function init(documentRef, fetchImpl, navigate, reload) {
        const forms = Array.from(documentRef.querySelectorAll('form[data-adn-json-action]'));
        for (const form of forms) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitButton = form.querySelector('button[type="submit"]');
                const status = documentRef.createElement('p');
                status.setAttribute('role', 'alert');
                status.className = 'text-sm text-red-700';
                form.querySelector('[data-adn-error]')?.remove();
                status.dataset.adnError = 'true';
                if (submitButton) {
                    submitButton.disabled = true;
                }
                try {
                    const response = await fetchImpl(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        throw new Error(typeof payload.message === 'string'
                            ? payload.message : 'ADN review request failed.');
                    }
                    if (form.dataset.adnJsonAction === 'import') {
                        const url = payload.data?.redirect_url;
                        if (typeof url !== 'string' || !url.startsWith('/')) {
                            throw new Error('Missing bill URL in import response.');
                        }
                        navigate(url);
                    } else {
                        reload();
                    }
                } catch (error) {
                    status.textContent = error instanceof Error ? error.message : 'ADN review request failed.';
                    form.appendChild(status);
                } finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                }
            });
        }
        return forms.length;
    }

    function boot(documentRef, fetchImpl, navigate, reload) {
        if (!documentRef) {
            return;
        }
        const run = () => init(documentRef, fetchImpl, navigate, reload);
        if (documentRef.readyState === 'complete' || documentRef.readyState === 'interactive') {
            run();
        } else {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        }
    }

    return { init, boot };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined' && globalThis.NfseAdnReviewBrowser) {
    globalThis.NfseAdnReviewBrowser.boot(
        document,
        fetch,
        (url) => { globalThis.location.assign(url); },
        () => { globalThis.location.reload(); },
    );
}
