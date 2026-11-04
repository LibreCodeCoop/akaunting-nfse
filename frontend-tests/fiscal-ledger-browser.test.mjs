// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const ledger = require('../Resources/assets/js/fiscal-ledger-browser.js');

test('receipt-scoped artifact routes disallow arbitrary paths and artifact kinds', () => {
    assert.equal(ledger.artifactRoute('/1/nfse/ledger/__RECEIPT_ID__/__ARTIFACT__', 75, 'xml'),
        '/1/nfse/ledger/75/xml');
    assert.equal(ledger.artifactRoute('/1/nfse/ledger/__RECEIPT_ID__/__ARTIFACT__', 75, 'danfse'),
        '/1/nfse/ledger/75/danfse');
    assert.throws(() => ledger.artifactRoute('/1/nfse/ledger/__RECEIPT_ID__/__ARTIFACT__', 1, '../x'));
    assert.throws(() => ledger.artifactRoute('/1/nfse/ledger/__RECEIPT_ID__/__ARTIFACT__', '../75', 'xml'));
    assert.throws(() => ledger.route('//evil.example.com/__INVOICE_ID__', { __INVOICE_ID__: 1 }));
});

test('ledger browser reads API over the logged-in session', async () => {
    const calls = [];
    const result = await ledger.getJson(async (url, opts) => {
        calls.push({ url, opts });
        return { ok: true, json: async () => ({ data: [], meta: { total: 0 } }) };
    }, '/1/nfse/ledger/data');
    assert.deepEqual(result.data, []);
    assert.equal(calls[0].opts.credentials, 'same-origin');
    assert.equal(calls[0].opts.headers.Accept, 'application/json');
    assert.ok(!('Authorization' in calls[0].opts.headers));
    await assert.rejects(
        ledger.getJson(async () => ({ ok: false, status: 403 }), '/x'),
        /não autorizada/,
    );
});
