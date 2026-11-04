// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const bulk = require('../Resources/assets/js/bulk-progress-browser.js');

class FakeNode {
    constructor(tagName = 'div') {
        this.tagName = tagName;
        this.children = [];
        this.dataset = {};
        this.textContent = '';
        this.className = '';
        this.disabled = false;
    }
    appendChild(child) { this.children.push(child); }
    replaceChildren(...children) { this.children = children; }
}
const nodes = () => Object.fromEntries(
    ['nfse-bulk-runs', 'nfse-bulk-status', 'nfse-bulk-page', 'nfse-bulk-prev', 'nfse-bulk-next']
        .map(id => [id, new FakeNode()]),
);
function documentFor(elements) {
    return {
        createElement: tag => new FakeNode(tag),
        getElementById: id => elements[id],
    };
}
function descendants(node) {
    return [node, ...node.children.flatMap(descendants)];
}

test('bulk progress links only accept numeric invoice IDs', () => {
    assert.equal(bulk.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', 42),
        '/1/sales/invoices/42');
    assert.throws(() => bulk.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', '../admin'));
    assert.throws(() => bulk.invoiceUrl('/1/sales/invoices/__INVOICE_ID__', 0));
    assert.throws(() => bulk.invoiceUrl('https://not-an-invoice.test', 42));
});

test('bulk status requests use session JSON without API credentials', async () => {
    let request;
    const response = await bulk.getJson(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ data: [], meta: { current_page: 1, last_page: 1 } }) };
    }, '/1/nfse/bulk/runs?page=1');
    assert.equal(response.data.length, 0);
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.ok(!Object.hasOwn(request.options.headers, 'Authorization'));

    await assert.rejects(
        bulk.getJson(async () => ({ ok: false, status: 403 }), '/1/nfse/bulk/runs'),
        /Sem permissão/,
    );
    await assert.rejects(
        bulk.getJson(async () => ({ ok: true, json: async () => ({ data: {} }) }), '/x'),
        /Resposta de lotes inválida/,
    );
});

test('progress renders per-unit failure and link as text without interpreting HTML', () => {
    const elements = nodes();
    const doc = documentFor(elements);
    const malicious = '<img src=x onerror=alert(1)>';
    bulk.render(doc, { dataset: { invoiceUrl: '/1/sales/invoices/__INVOICE_ID__' } }, {
        data: [{ id: 12, status: 'partial', units: [{
            invoice_id: 37, emission_group_key: 'service:0107', status: 'blocked',
            receipt_id: null, error_type: 'readiness', error_message: malicious,
        }] }],
        meta: { current_page: 1, last_page: 1, total: 1 },
    });
    const tags = descendants(elements['nfse-bulk-runs']);
    assert.equal(tags.find(n => n.tagName === 'a').href, '/1/sales/invoices/37');
    assert.equal(tags.find(n => n.tagName === 'tr' && n.dataset.bulkUnitStatus === 'blocked')
        ?.dataset.bulkUnitStatus, 'blocked');
    assert.ok(tags.some(n => n.textContent.includes(malicious)));
    assert.equal(elements['nfse-bulk-status'].textContent, '1 lote(s) registrado(s).');
    assert.equal(elements['nfse-bulk-next'].disabled, true);
});
