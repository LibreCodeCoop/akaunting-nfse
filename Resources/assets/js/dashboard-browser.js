// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseDashboardBrowser = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function init(documentRef, fetchImpl) {
        const root = documentRef?.getElementById('nfse-dashboard');
        const feedback = documentRef?.getElementById('nfse-dashboard-feedback');
        const rows = documentRef?.getElementById('nfse-dashboard-receipts');
        if (!root || !feedback || !rows || root.dataset.booted === 'true') return;
        root.dataset.booted = 'true';

        const metric = (name, value) => {
            const target = documentRef.getElementById('nfse-dashboard-' + name);
            if (!target || !Number.isSafeInteger(value) || value < 0) {
                throw new Error('Invalid fiscal dashboard metric');
            }
            target.textContent = String(value);
        };
        const td = (row, text) => {
            const cell = documentRef.createElement('td');
            cell.className = 'p-3';
            cell.textContent = text || '—';
            row.appendChild(cell);
            return cell;
        };

        function display(data) {
            if (!data || !data.stats || !Array.isArray(data.recent_receipts)
                || typeof data.stats.sandbox_mode !== 'boolean') {
                throw new Error('Invalid fiscal dashboard JSON');
            }
            metric('total', data.stats.total);
            metric('emitted', data.stats.emitted);
            metric('cancelled', data.stats.cancelled);
            documentRef.getElementById('nfse-dashboard-environment').textContent =
                data.stats.sandbox_mode ? root.dataset.sandboxLabel : root.dataset.productionLabel;

            rows.replaceChildren();
            if (data.recent_receipts.length === 0) {
                const row = documentRef.createElement('tr');
                const cell = td(row, root.dataset.emptyMessage);
                cell.colSpan = 5;
                rows.appendChild(row);
                return;
            }

            for (const receipt of data.recent_receipts) {
                if (!receipt || !Number.isSafeInteger(receipt.invoice_id) || receipt.invoice_id <= 0
                    || typeof receipt.invoice_url !== 'string'
                    || !receipt.invoice_url.startsWith('/')) {
                    throw new Error('Invalid fiscal dashboard receipt');
                }
                const row = documentRef.createElement('tr');
                td(row, receipt.nfse_number);
                td(row, receipt.customer_name);
                td(row, receipt.issue_date);
                td(row, receipt.status_label);
                const actions = td(row, '');
                actions.className = 'p-3 text-right';
                const link = documentRef.createElement('a');
                link.className = 'text-indigo-700 underline';
                link.href = receipt.invoice_url;
                link.textContent = root.dataset.invoiceLabel || 'Invoice';
                actions.appendChild(link);
                rows.appendChild(row);
            }
        }

        void fetchImpl(root.dataset.readUrl, {
            method: 'GET', credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(async (response) => {
            if (!response.ok) throw new Error('Fiscal dashboard access denied');
            const payload = await response.json();
            display(payload?.data);
        }).catch(() => {
            feedback.textContent = root.dataset.errorMessage;
            feedback.hidden = false;
            feedback.focus();
        });
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

if (typeof document !== 'undefined' && typeof fetch !== 'undefined' && globalThis.NfseDashboardBrowser) {
    globalThis.NfseDashboardBrowser.boot(document, fetch);
}
