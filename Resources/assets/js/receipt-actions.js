// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseReceiptActions = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function accessKeyFor(button) {
        const receipt = button?.closest?.('[data-nfse-receipt-id]');
        const text = receipt?.querySelector?.('[data-nfse-access-key]')?.textContent;

        return typeof text === 'string' ? text.trim() : '';
    }

    function setCopyFeedback(button, success) {
        const feedback = button.closest('[data-nfse-receipt-id]')?.querySelector('[data-nfse-copy-feedback]');

        if (!feedback) {
            return;
        }

        feedback.textContent = success ? (button.dataset.nfseCopySuccess || '') : (button.dataset.nfseCopyError || '');
        feedback.classList.toggle('text-green-700', success);
        feedback.classList.toggle('text-red-700', !success);
    }

    async function copyAccessKey(button, options = {}) {
        if (!button || button.disabled) {
            return false;
        }

        const key = accessKeyFor(button);

        if (!key || key === '—') {
            return false;
        }

        const clipboard = options.clipboard ?? globalThis.navigator?.clipboard;

        if (typeof clipboard?.writeText !== 'function') {
            setCopyFeedback(button, false);
            return false;
        }

        button.disabled = true;

        try {
            await clipboard.writeText(key);
            setCopyFeedback(button, true);
            return true;
        } catch {
            setCopyFeedback(button, false);
            return false;
        } finally {
            button.disabled = false;
        }
    }

    function syncDisclosure(details) {
        const label = details?.querySelector?.('[data-nfse-disclosure-label]');

        if (label) {
            const text = details.open ? details.dataset.nfseLabelHide : details.dataset.nfseLabelShow;

            if (text) {
                label.textContent = text;
            }
        }

        const chevron = details?.querySelector?.('[data-nfse-disclosure-chevron]');

        if (chevron) {
            chevron.style.transform = details.open ? 'rotate(180deg)' : 'rotate(0deg)';
        }
    }

    function boot(documentRef, options = {}) {
        const panel = documentRef?.querySelector?.('[data-nfse-native-panel="true"]');

        if (!panel || panel.dataset.nfseReceiptActionsBooted === 'true') {
            return false;
        }

        panel.dataset.nfseReceiptActionsBooted = 'true';
        panel.addEventListener('click', (event) => {
            const button = event.target?.closest?.('[data-nfse-copy-access-key="true"]');

            if (button) {
                void copyAccessKey(button, options);
            }
        });

        panel.querySelectorAll('[data-nfse-history-artifacts], [data-nfse-native-substitute], [data-nfse-native-cancel]')
            .forEach((details) => {
                syncDisclosure(details);
                details.addEventListener('toggle', () => syncDisclosure(details));
            });

        return true;
    }

    return { accessKeyFor, copyAccessKey, syncDisclosure, boot };
});

if (typeof document !== 'undefined' && typeof globalThis !== 'undefined' && globalThis.NfseReceiptActions) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => globalThis.NfseReceiptActions.boot(document), { once: true });
    } else {
        globalThis.NfseReceiptActions.boot(document);
    }
}
