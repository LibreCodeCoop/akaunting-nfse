// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const api = require('../Resources/assets/js/fiscal-tax-admin.js');

test('tax API errors keep sensitive server responses out of unauthorized feedback', () => {
    assert.equal(api.errorMessage(403, { message: 'internal secret should never appear' }),
        'Acesso não autorizado à configuração fiscal.');
    assert.equal(api.errorMessage(422, { errors: { source_version: ['Version already registered'] } }),
        'Version already registered');
    assert.equal(api.errorMessage(409, { message: 'Retificação fiscal rejeitada.' }),
        'Retificação fiscal rejeitada.');
    assert.equal(api.errorMessage(500, { message: 'internal stack trace should never appear' }),
        'Serviço fiscal temporariamente indisponível.');
});

test('tax browser requests use JSON with same-origin session', async () => {
    const sent = [];
    const data = await api.getJson(async (url, options) => {
        sent.push({ url, options });
        return { ok: true, json: async () => ({ data: [1], meta: { total: 1 } }) };
    }, '/1/nfse/settings/approximate-taxes/resources');
    assert.deepEqual(data.data, [1]);
    assert.equal(sent[0].options.credentials, 'same-origin');
    assert.equal(sent[0].options.headers.Accept, 'application/json');
    await assert.rejects(
        api.getJson(async () => ({ status: 403, ok: false, json: async () =>
            ({ message: 'internal secret should never appear' }) }), '/x'),
        /Acesso não autorizado/,
    );
});
