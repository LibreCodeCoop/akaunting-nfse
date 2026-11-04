// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseBulkProgressBrowser = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function invoiceUrl(template, id) {
        const number = Number(id);
        if (!Number.isSafeInteger(number) || number <= 0 || !template.includes('__INVOICE_ID__')) {
            throw new Error('Invalid invoice identifier.');
        }
        return template.replace('__INVOICE_ID__', String(number));
    }

    function addText(doc, parent, tag, value, className = '') {
        const element = doc.createElement(tag);
        element.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
        if (className) element.className = className;
        parent.appendChild(element);
        return element;
    }

    function render(doc, root, payload) {
        if (!Array.isArray(payload?.data) || !payload.meta
            || !Number.isInteger(payload.meta.current_page)
            || !Number.isInteger(payload.meta.last_page)) {
            throw new Error('Resposta de lotes inválida.');
        }
        const target = doc.getElementById('nfse-bulk-runs');
        target.replaceChildren();

        for (const run of payload.data) {
            if (!Array.isArray(run.units)) throw new Error('Unidades do lote inválidas.');
            const section = doc.createElement('section');
            section.className = 'rounded border bg-white';
            const heading = doc.createElement('h2');
            heading.className = 'border-b px-4 py-3 font-semibold';
            heading.textContent = 'Lote #' + run.id + ' — ' + run.status;
            section.appendChild(heading);
            const scroll = doc.createElement('div');
            scroll.className = 'overflow-x-auto';
            const table = doc.createElement('table');
            table.className = 'min-w-full text-sm';
            const thead = doc.createElement('thead');
            thead.className = 'bg-gray-50';
            const headRow = doc.createElement('tr');
            for (const text of ['Fatura', 'Grupo fiscal', 'Estado', 'Recibo', 'Erro']) {
                addText(doc, headRow, 'th', text, 'px-3 py-2 text-left');
            }
            thead.appendChild(headRow);
            table.appendChild(thead);
            const tbody = doc.createElement('tbody');
            for (const unit of run.units) {
                const tr = doc.createElement('tr');
                tr.className = 'border-t';
                tr.dataset.bulkUnitStatus = String(unit.status || 'unknown');
                const linkCell = doc.createElement('td');
                linkCell.className = 'px-3 py-2';
                const link = addText(doc, linkCell, 'a', '#' + unit.invoice_id, 'text-indigo-700 underline');
                link.href = invoiceUrl(root.dataset.invoiceUrl, unit.invoice_id);
                tr.appendChild(linkCell);
                addText(doc, tr, 'td', unit.emission_group_key, 'px-3 py-2 max-w-md break-all font-mono text-xs');
                addText(doc, tr, 'td', unit.status, 'px-3 py-2');
                addText(doc, tr, 'td', unit.receipt_id, 'px-3 py-2');
                addText(doc, tr, 'td',
                    [unit.error_type, unit.error_message].filter(Boolean).join(': '), 'px-3 py-2');
                tbody.appendChild(tr);
            }
            table.appendChild(tbody);
            scroll.appendChild(table);
            section.appendChild(scroll);
            target.appendChild(section);
        }

        const count = Number(payload.meta.total || 0);
        doc.getElementById('nfse-bulk-status').textContent = count
            ? count + ' lote(s) registrado(s).' : 'Nenhum lote registrado.';
        doc.getElementById('nfse-bulk-page').textContent =
            'Página ' + payload.meta.current_page + ' de ' + Math.max(1, payload.meta.last_page);
        doc.getElementById('nfse-bulk-prev').disabled = payload.meta.current_page <= 1;
        doc.getElementById('nfse-bulk-next').disabled = payload.meta.current_page >= payload.meta.last_page;
    }

    async function getJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            throw new Error([401, 403].includes(response.status)
                ? 'Sem permissão para consultar lotes fiscais.'
                : 'Falha ao consultar o andamento dos lotes.');
        }
        const payload = await response.json();
        if (!Array.isArray(payload?.data) || !payload.meta) {
            throw new Error('Resposta de lotes inválida.');
        }
        return payload;
    }

    function init(doc, root, fetchImpl) {
        let page = 1;
        let busy = false;
        async function load(next) {
            if (busy) return;
            busy = true;
            const status = doc.getElementById('nfse-bulk-status');
            status.textContent = 'Consultando andamento dos lotes...';
            try {
                const separator = root.dataset.runsUrl.includes('?') ? '&' : '?';
                const payload = await getJson(fetchImpl, root.dataset.runsUrl + separator + 'page=' + next);
                render(doc, root, payload);
                page = payload.meta.current_page;
            } catch (error) {
                status.textContent = error.message;
            } finally {
                busy = false;
            }
        }
        doc.getElementById('nfse-bulk-prev').addEventListener('click', () => load(page - 1));
        doc.getElementById('nfse-bulk-next').addEventListener('click', () => load(page + 1));
        doc.getElementById('nfse-bulk-refresh').addEventListener('click', () => load(page));
        return load(1);
    }

    if (typeof document !== 'undefined') {
        const boot = () => {
            const root = document.getElementById('nfse-bulk-progress');
            if (root) init(document, root, globalThis.fetch.bind(globalThis));
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
    }
    return { invoiceUrl, render, getJson, init };
});
