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
