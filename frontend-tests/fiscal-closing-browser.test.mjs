// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const closing = require('../Resources/assets/js/fiscal-closing-browser.js');

test('closing invoice links reject unsafe IDs', () => {
    assert.equal(closing.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', 99),
        '/1/sales/invoices/99');
    assert.throws(() => closing.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', '../admin'));
    assert.throws(() => closing.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', 0));
    assert.throws(() => closing.invoiceUrl('https://unsafe.example.test', 99));
});

test('closing browser requests same-origin JSON without external credentials', async () => {
    let seen;
    const result = await closing.getJson(async (url, opts) => {
        seen = { url, opts };
        return { ok: true, json: async () => ({ data: {}, meta: {} }) };
    }, '/1/nfse/closing/data?competence=2026-10');
    assert.ok(result.meta);
    assert.equal(seen.opts.credentials, 'same-origin');
    assert.equal(seen.opts.headers.Accept, 'application/json');
    assert.ok(!('Authorization' in seen.opts.headers));

    await assert.rejects(
        closing.getJson(async () => ({ ok: false, status: 403 }), '/1/nfse/closing/data'),
        /Consulta fiscal não autorizada/,
    );
    await assert.rejects(
        closing.getJson(async () => ({ ok: true, json: async () => ({ data: [] }) }), '/x'),
        /Resposta fiscal inválida/,
    );
});

test('closing browser renders documentary IBS/CBS statuses without implying tax payable', () => {
    const nodes = new Map();
    function node() {
        return {
            textContent: '',
            hidden: false,
            disabled: false,
            children: [],
            replaceChildren() { this.children = []; },
            appendChild(value) { this.children.push(value); },
        };
    }
    const doc = {
        getElementById(id) {
            if (!nodes.has(id)) nodes.set(id, node());
            return nodes.get(id);
        },
        createElement() { return node(); },
    };
    const root = { dataset: {
        invoiceUrl: '/1/sales/invoices/__INVOICE_ID__',
        evidenceSummaryTemplate: 'IBS informado em :ibs_reported documento(s), ausente em :ibs_missing; '
            + 'CBS informada em :cbs_reported documento(s), ausente em :cbs_missing. '
            + 'XML sem evidência: :snapshots_missing; fontes duplicadas: :duplicates.',
        evidenceUnavailable: 'Evidência IBS/CBS indisponível.',
    } };
    const payload = {
        data: {
            summary: {
                gross_service_value: '100.00',
                iss: { value: '2.00' },
                documents: { emitted: 1 },
                reconciliation_mismatches: 0,
            },
            ibs_cbs_evidence: {
                summary: {
                    ibs: { reported_count: 1, not_reported_count: 1 },
                    cbs: { reported_count: 0, not_reported_count: 2 },
                    missing_snapshots: 1,
                    duplicate_documents: 0,
                },
            },
            reconciliation: [],
            rows: [],
            commercial_review: {
                rows: [],
                meta: { current_page: 1, last_page: 1, total: 0 },
            },
        },
        meta: { current_page: 1, last_page: 1 },
    };

    closing.renderClosing(doc, root, payload);

    assert.match(doc.getElementById('closing-ibs-cbs-summary').textContent,
        /IBS informado em 1 documento\(s\), ausente em 1/);
    assert.match(doc.getElementById('closing-ibs-cbs-summary').textContent,
        /fontes duplicadas: 0/);
    // A second locale must render counts without Portuguese hard-coding.
    const englishRoot = { dataset: {
        ...root.dataset,
        evidenceSummaryTemplate: 'IBS reported :ibs_reported, missing :ibs_missing; '
            + 'CBS reported :cbs_reported; duplicate sources: :duplicates.',
    } };
    closing.renderClosing(doc, englishRoot, payload);
    assert.match(doc.getElementById('closing-ibs-cbs-summary').textContent,
        /IBS reported 1, missing 1; CBS reported 0; duplicate sources: 0/);
    assert.equal(doc.getElementById('closing-gross').textContent, '100.00');
    assert.equal(doc.getElementById('nfse-closing-mismatch').hidden, true);
});
