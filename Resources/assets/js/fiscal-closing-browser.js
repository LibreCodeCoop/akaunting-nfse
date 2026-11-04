// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseFiscalClosingBrowser = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function invoiceUrl(template, id) {
        const num = Number(id);
        if (!Number.isSafeInteger(num) || num <= 0 || !template.includes('__INVOICE_ID__')) {
            throw new Error('Invalid invoice identifier.');
        }
        return template.replace('__INVOICE_ID__', String(num));
    }

    function textElement(doc, tag, text, className = '') {
        const el = doc.createElement(tag);
        el.textContent = text == null || text === '' ? '—' : String(text);
        if (className) el.className = className;
        return el;
    }

    async function getJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error([401, 403].includes(response.status)
            ? 'Consulta fiscal não autorizada.' : 'Não foi possível consultar o fechamento fiscal.');
        const json = await response.json();
        if (!json || typeof json.data !== 'object' || json.data === null || !json.meta) {
            throw new Error('Resposta fiscal inválida.');
        }
        return json;
    }

    function setText(doc, id, value) {
        const field = doc.getElementById(id);
        if (field) field.textContent = value == null || value === '' ? '—' : String(value);
    }

    function addRow(doc, body, cells, linkTemplate = null, firstId = null) {
        const row = doc.createElement('tr');
        row.className = 'border-t';
        for (const [i, value] of cells.entries()) {
            const cell = doc.createElement('td');
            cell.className = 'px-3 py-2';
            if (i === 0 && linkTemplate && firstId != null) {
                const a = textElement(doc, 'a', value, 'underline');
                a.href = invoiceUrl(linkTemplate, firstId);
                cell.appendChild(a);
            } else {
                cell.textContent = value == null || value === '' ? '—' : String(value);
            }
            row.appendChild(cell);
        }
        body.appendChild(row);
    }

    function renderRows(doc, body, rows, columns, empty, template = null) {
        body.replaceChildren();
        if (!Array.isArray(rows)) throw new Error('Resposta fiscal contém listagem inválida.');
        if (!rows.length) {
            const row = doc.createElement('tr');
            const cell = textElement(doc, 'td', empty, 'px-3 py-4 text-gray-600');
            cell.colSpan = columns.length;
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }
        for (const r of rows) {
            addRow(doc, body, columns.map((c) => {
                if (c === 'invoice_number') return r[c] || ('#' + r.invoice_id);
                if (c === 'status_label') return ({
                    not_linked: 'Sem NFS-e vinculada no módulo',
                    reconciliation_required: 'Recuperação ou conciliação necessária',
                    partially_linked: 'Grupos fiscais parcialmente vinculados',
                    authorized_locally: 'NFS-e autorizada localmente (não comprova conformidade integral)',
                })[r.status] || 'Revisão fiscal necessária';
                return r[c];
            }), template, template ? r.invoice_id : null);
        }
    }

    function renderClosing(doc, root, payload) {
        const data = payload.data;
        if (!data.summary || !Array.isArray(data.reconciliation)
            || !Array.isArray(data.rows) || !data.commercial_review
            || !Array.isArray(data.commercial_review.rows)) {
            throw new Error('Resposta fiscal incompleta.');
        }
        const summary = data.summary;
        setText(doc, 'closing-gross', summary.gross_service_value);
        setText(doc, 'closing-iss', summary.iss?.value);
        setText(doc, 'closing-emitted', summary.documents?.emitted);
        setText(doc, 'closing-mismatches', summary.reconciliation_mismatches);

        const warnings = doc.getElementById('closing-reconciliation');
        warnings.replaceChildren();
        doc.getElementById('nfse-closing-mismatch').hidden = data.reconciliation.length === 0;
        for (const item of data.reconciliation) {
            warnings.appendChild(textElement(doc, 'li',
                (item.invoice_number || ('#' + item.invoice_id)) + ': '
                + item.invoice_amount + ' / ' + item.authorized_active_gross
                + ' (' + item.reason + ')'));
        }

        const commercial = data.commercial_review;
        const meta = commercial.meta || {};
        setText(doc, 'closing-commercial-count',
            String(meta.total || 0) + ' fatura(s) no mês comercial, 30 por página.');
        renderRows(doc, doc.getElementById('closing-commercial-body'), commercial.rows,
            ['invoice_number', 'issued_at', 'financial_status', 'local_receipts', 'status_label'],
            'Nenhuma fatura neste mês comercial.', root.dataset.invoiceUrl);
        renderRows(doc, doc.getElementById('closing-fiscal-body'), data.rows,
            ['invoice_number', 'nfse_number', 'status', 'gross_service_value',
                'taxation_group', 'iss_retention', 'reconciliation_status'],
            'Nenhum documento fiscal para a competência.');

        const current = Number(payload.meta.current_page) || 1;
        const total = Number(payload.meta.last_page) || 1;
        const commercialCurrent = Number(meta.current_page) || 1;
        const commercialTotal = Number(meta.last_page) || 1;
        setText(doc, 'closing-page', 'Página ' + current + ' de ' + total);
        setText(doc, 'commercial-page', 'Página ' + commercialCurrent + ' de ' + commercialTotal);
        doc.getElementById('closing-prev').disabled = current <= 1;
        doc.getElementById('closing-next').disabled = current >= total;
        doc.getElementById('commercial-prev').disabled = commercialCurrent <= 1;
        doc.getElementById('commercial-next').disabled = commercialCurrent >= commercialTotal;
    }

    function init(doc, fetchImpl) {
        const root = doc.getElementById('nfse-closing');
        if (!root) return false;
        const date = doc.getElementById('nfse-closing-competence');
        const status = doc.getElementById('nfse-closing-status');
        let reportPage = 1;
        let commercialPage = 1;
        let reportLast = 1;
        let commercialLast = 1;
        const load = async () => {
            status.textContent = 'Consultando dados fiscais pela API...';
            try {
                const url = new URL(root.dataset.listUrl, doc.baseURI);
                url.searchParams.set('competence', date.value);
                url.searchParams.set('report_page', String(reportPage));
                url.searchParams.set('page', String(commercialPage));
                const payload = await getJson(fetchImpl, url.toString());
                renderClosing(doc, root, payload);
                reportLast = Number(payload.meta.last_page) || 1;
                commercialLast = Number(payload.data.commercial_review.meta?.last_page) || 1;
                status.textContent = '';
            } catch (error) {
                status.textContent = error.message || 'Erro na consulta fiscal.';
                for (const id of ['closing-gross', 'closing-iss', 'closing-emitted', 'closing-mismatches']) {
                    setText(doc, id, '—');
                }
                doc.getElementById('closing-commercial-body').replaceChildren();
                doc.getElementById('closing-fiscal-body').replaceChildren();
                doc.getElementById('nfse-closing-mismatch').hidden = true;
            }
        };
        for (const [id, mode, delta] of [
            ['closing-prev', 'report', -1], ['closing-next', 'report', 1],
            ['commercial-prev', 'commercial', -1], ['commercial-next', 'commercial', 1],
        ]) {
            doc.getElementById(id).addEventListener('click', () => {
                if (mode === 'report') reportPage = Math.max(1, Math.min(reportLast, reportPage + delta));
                else commercialPage = Math.max(1, Math.min(commercialLast, commercialPage + delta));
                void load();
            });
        }
        void load();
        return true;
    }

    function boot(doc, fetchImpl) {
        if (!doc) return;
        const run = () => init(doc, fetchImpl);
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', run, { once: true });
        else run();
    }
    return { init, boot, getJson, invoiceUrl, renderClosing };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined' && globalThis.NfseFiscalClosingBrowser) {
    globalThis.NfseFiscalClosingBrowser.boot(document, fetch);
}
