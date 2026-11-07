// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseFiscalLedgerBrowser = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function route(template, placeholders) {
        let url = String(template);
        for (const [placeholder, value] of Object.entries(placeholders)) {
            if (!url.includes(placeholder) || !Number.isSafeInteger(Number(value)) || Number(value) < 1) {
                throw new Error('Invalid fiscal identifier.');
            }
            url = url.replace(placeholder, String(value));
        }
        if (!url.startsWith('/') || url.startsWith('//')) {
            throw new Error('Invalid fiscal route.');
        }
        return url;
    }

    function artifactRoute(template, receiptId, type) {
        if (!['xml', 'danfse'].includes(type) || !template.includes('__ARTIFACT__')) {
            throw new Error('Invalid fiscal artifact.');
        }
        return route(template, { __RECEIPT_ID__: receiptId }).replace('__ARTIFACT__', type);
    }

    function archivedPdfRoute(template, receiptId, pageNumber) {
        const url = artifactRoute(template, receiptId, 'danfse');
        const page = Number(pageNumber);
        if (!Number.isSafeInteger(page) || page < 1 || page > 10000) {
            throw new Error('Invalid archival PDF page.');
        }
        return url + '#page=' + page;
    }

    async function getJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            method: 'GET', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            throw new Error([401, 403].includes(response.status)
                ? 'Consulta ao livro fiscal não autorizada.'
                : 'Não foi possível consultar o livro fiscal.');
        }
        const payload = await response.json();
        if (!payload || !Array.isArray(payload.data) || !payload.meta) {
            throw new Error('Resposta fiscal inválida.');
        }
        return payload;
    }

    function textElement(doc, tag, text, className = '') {
        const node = doc.createElement(tag);
        node.textContent = text == null || text === '' ? '—' : String(text);
        if (className) node.className = className;
        return node;
    }

    function formatDate(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '—';
        return value.slice(8, 10) + '/' + value.slice(5, 7) + '/' + value.slice(0, 4);
    }

    function render(doc, root, payload) {
        const body = doc.getElementById('nfse-ledger-rows');
        body.replaceChildren();
        if (!payload.data.length) {
            const row = doc.createElement('tr');
            const cell = textElement(doc, 'td', 'Nenhum recibo fiscal encontrado.', 'p-4 text-gray-600');
            cell.colSpan = 5;
            row.appendChild(cell);
            body.appendChild(row);
        }
        for (const receipt of payload.data) {
            const tr = doc.createElement('tr');
            for (const value of [
                receipt.nfse_number, receipt.customer, formatDate(receipt.issue_date),
                root.dataset['status' + String(receipt.status || '').charAt(0).toUpperCase()
                    + String(receipt.status || '').slice(1)] || receipt.status,
            ]) {
                tr.appendChild(textElement(doc, 'td', value, 'p-3'));
            }
            const actions = doc.createElement('td');
            actions.className = 'p-3 text-right';
            if (receipt.invoice_id != null) {
                const invoice = textElement(doc, 'a', 'Abrir fatura', 'text-indigo-700 underline');
                invoice.href = route(root.dataset.invoiceUrl, { __INVOICE_ID__: receipt.invoice_id });
                actions.appendChild(invoice);
            } else {
                actions.appendChild(textElement(doc, 'span',
                    root.dataset.historicalNoInvoice || 'Sem fatura histórica', 'text-gray-500'));
            }
            for (const kind of ['xml', 'danfse']) {
                const allowed = kind === 'xml' ? receipt.can_download_xml === true
                    : receipt.can_download_danfse === true;
                if (!allowed) continue;
                const file = textElement(doc, 'a', kind === 'xml' ? 'XML' : 'DANFSe',
                    'ml-3 text-indigo-700 underline');
                file.href = kind === 'danfse' && receipt.historical_unlinked === true
                    && receipt.archive_page_number != null
                    ? archivedPdfRoute(root.dataset.artifactUrl, receipt.id, receipt.archive_page_number)
                    : artifactRoute(root.dataset.artifactUrl, receipt.id, kind);
                actions.appendChild(file);
            }
            tr.appendChild(actions);
            body.appendChild(tr);
        }
        const page = Number(payload.meta.current_page) || 1;
        const pages = Number(payload.meta.last_page) || 1;
        doc.getElementById('nfse-ledger-page').textContent =
            'Página ' + page + ' de ' + pages + ' · ' + String(payload.meta.total ?? 0) + ' recibo(s)';
        doc.getElementById('nfse-ledger-prev').disabled = page <= 1;
        doc.getElementById('nfse-ledger-next').disabled = page >= pages;
    }

    function init(doc, fetchImpl) {
        const root = doc.getElementById('nfse-ledger-browser');
        if (!root) return false;
        const status = doc.getElementById('nfse-ledger-status');
        const form = doc.getElementById('nfse-ledger-filter');
        const query = new URL(doc.baseURI).searchParams;
        let page = Math.max(1, Number.parseInt(query.get('page') || '1', 10) || 1);
        let last = 1;
        const load = async () => {
            status.textContent = 'Consultando o livro fiscal pela API...';
            const url = new URL(root.dataset.listUrl, doc.baseURI);
            for (const [name, value] of new FormData(form).entries()) {
                if (typeof value === 'string' && value.trim()) url.searchParams.set(name, value.trim());
            }
            url.searchParams.set('page', String(page));
            try {
                const payload = await getJson(fetchImpl, url.toString());
                last = Number(payload.meta.last_page) || 1;
                page = Number(payload.meta.current_page) || 1;
                render(doc, root, payload);
                status.textContent = '';
            } catch (error) {
                status.textContent = error.message || 'Erro na consulta fiscal.';
                doc.getElementById('nfse-ledger-rows').replaceChildren();
                doc.getElementById('nfse-ledger-prev').disabled = true;
                doc.getElementById('nfse-ledger-next').disabled = true;
            }
        };
        doc.getElementById('nfse-ledger-prev').addEventListener('click', () => {
            if (page > 1) { page--; void load(); }
        });
        doc.getElementById('nfse-ledger-next').addEventListener('click', () => {
            if (page < last) { page++; void load(); }
        });
        void load();
        return true;
    }

    function boot(doc, fetchImpl) {
        if (!doc) return;
        const run = () => init(doc, fetchImpl);
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', run, { once: true });
        else run();
    }
    return { init, boot, route, artifactRoute, archivedPdfRoute, getJson, render };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined' && globalThis.NfseFiscalLedgerBrowser) {
    globalThis.NfseFiscalLedgerBrowser.boot(document, fetch);
}
