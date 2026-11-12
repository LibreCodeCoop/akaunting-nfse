// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { init } = require('../Resources/assets/js/dashboard-browser.js');

function element(dataset = {}) {
    return {
        dataset, textContent: '', hidden: true, children: [], focused: false,
        appendChild(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
        focus() { this.focused = true; },
    };
}
function documentFor(els) {
    return {
        getElementById(id) { return els[id] ?? null; },
        createElement(tag) { return Object.assign(element(), { tag }); },
    };
}

test('dashboard loads JSON without rendering untrusted data as HTML', async () => {
    const root = element({
        readUrl: '/1/nfse/dashboard/data',
        sandboxLabel: 'Restricted',
        productionLabel: 'Production',
        invoiceLabel: 'Invoice',
    });
    const rows = element();
    const els = {
        'nfse-dashboard': root,
        'nfse-dashboard-feedback': element(),
        'nfse-dashboard-receipts': rows,
        'nfse-dashboard-total': element(),
        'nfse-dashboard-emitted': element(),
        'nfse-dashboard-cancelled': element(),
        'nfse-dashboard-environment': element(),
    };
    const calls = [];
    init(documentFor(els), async (url, options) => {
        calls.push({ url, options });
        return { ok: true, json: async () => ({ data: {
            stats: { total: 2, emitted: 1, cancelled: 1, sandbox_mode: false },
            recent_receipts: [{
                invoice_id: 13, nfse_number: '43', customer_name: '<script>not HTML</script>',
                issue_date: '2026-11-11', status_label: 'Emitted', invoice_url: '/1/sales/invoices/13',
            }],
        } }) };
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(els['nfse-dashboard-total'].textContent, '2');
    assert.equal(els['nfse-dashboard-environment'].textContent, 'Production');
    assert.equal(rows.children[0].children[1].textContent, '<script>not HTML</script>');
    assert.equal(rows.children[0].children[4].children[0].href, '/1/sales/invoices/13');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    assert.equal(calls[0].options.credentials, 'same-origin');
});

test('dashboard fails closed on invalid JSON without fabricated figures', async () => {
    const root = element({ readUrl: '/1/nfse/dashboard/data', errorMessage: 'Access unavailable' });
    const feedback = element();
    const total = element();
    init(documentFor({
        'nfse-dashboard': root, 'nfse-dashboard-feedback': feedback,
        'nfse-dashboard-receipts': element(), 'nfse-dashboard-total': total,
    }), async () => ({ ok: false }));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(feedback.textContent, 'Access unavailable');
    assert.equal(feedback.focused, true);
    assert.equal(total.textContent, '');
});
