// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseFiscalJsonForms = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const FORM_SELECTOR = 'form[data-nfse-fiscal-json-form="true"]';
    const MESSAGES = {
        nfseJsonUncertain: 'The result is uncertain. Reload the page and check the history before retrying.',
        nfseJsonUnauthenticated: 'Sign in again before continuing.',
        nfseJsonForbidden: 'You do not have permission for this operation.',
        nfseJsonExpired: 'Your session has expired. Reload before continuing.',
        nfseJsonInvalid: 'Invalid values. Review the fields before retrying.',
        nfseJsonSuccess: 'Saved. Reloading the page.',
    };

    function message(form, key) {
        const panel = form.closest?.('[data-nfse-native-panel="true"]');
        return (key === 'nfseJsonSuccess' ? form.dataset[key] : panel?.dataset?.[key]) || MESSAGES[key];
    }

    function feedback(form, message) {
        let node = form.querySelector('[data-nfse-fiscal-json-feedback]');
        if (!node) {
            node = form.ownerDocument.createElement('p');
            node.setAttribute('data-nfse-fiscal-json-feedback', 'true');
            node.setAttribute('role', 'alert');
            node.className = 'mt-2 text-sm text-red-700';
            form.appendChild(node);
        }
        node.textContent = message;
    }

    async function submit(form, options = {}) {
        if (!form || form.dataset.nfseSubmitting) return { kind: 'already-submitted' };
        const button = form.querySelector('button[type="submit"]');
        form.dataset.nfseSubmitting = 'inflight';
        if (button) button.disabled = true;

        let response;
        try {
            response = await (options.fetchImpl ?? globalThis.fetch)(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: (options.formDataFactory ?? ((element) => new FormData(element)))(form),
            });
        } catch {
            form.dataset.nfseSubmitting = 'uncertain';
            feedback(form, message(form, 'nfseJsonUncertain'));
            return { kind: 'uncertain' };
        }

        let payload;
        try {
            payload = await response.json();
        } catch {
            form.dataset.nfseSubmitting = 'uncertain';
            feedback(form, message(form, 'nfseJsonUncertain'));
            return { kind: 'uncertain' };
        }

        const validDecision = form.dataset.nfseJsonContract === 'decision'
            && payload?.data && typeof payload.data === 'object' && !Array.isArray(payload.data);
        const validEvidence = form.dataset.nfseJsonContract === 'evidence'
            && payload?.status === 'attested';
        if (response.status === 201 && (validDecision || validEvidence)) {
            feedback(form, message(form, 'nfseJsonSuccess'));
            (options.reload ?? (() => globalThis.location.reload()))();
            return { kind: 'success' };
        }

        const errorKeys = {
            401: 'nfseJsonUnauthenticated',
            403: 'nfseJsonForbidden',
            419: 'nfseJsonExpired',
            422: 'nfseJsonInvalid',
        };
        if (Object.prototype.hasOwnProperty.call(errorKeys, response.status)) {
            delete form.dataset.nfseSubmitting;
            if (button) button.disabled = false;
            feedback(form, message(form, errorKeys[response.status]));
            return { kind: 'rejected', status: response.status };
        }

        // A 5xx, redirect, unexpected payload or transport failure may follow
        // a committed server-side action. Never silently replay the POST.
        form.dataset.nfseSubmitting = 'uncertain';
        feedback(form, message(form, 'nfseJsonUncertain'));
        return { kind: 'uncertain' };
    }

    function boot(documentRef, options = {}) {
        const panel = documentRef?.querySelector?.('[data-nfse-native-panel="true"]');
        if (!panel || panel.dataset.nfseFiscalJsonFormsBooted === 'true') return false;
        panel.dataset.nfseFiscalJsonFormsBooted = 'true';
        panel.addEventListener('submit', (event) => {
            if (!event.target?.matches?.(FORM_SELECTOR)) return;
            event.preventDefault();
            void submit(event.target, options);
        });
        return true;
    }

    return { submit, boot };
});

if (typeof document !== 'undefined' && globalThis.NfseFiscalJsonForms) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => globalThis.NfseFiscalJsonForms.boot(document), { once: true });
    } else {
        globalThis.NfseFiscalJsonForms.boot(document);
    }
}
