// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const dps = require('../Resources/assets/js/dps-readiness.js');

test('DPS query permits only a canonical positive national number', () => {
    assert.equal(dps.lookupUrl('/1/nfse/dps/lookup', '900000000000008'),
        '/1/nfse/dps/lookup?number=900000000000008');
    assert.equal(dps.lookupUrl('/1/nfse/dps/lookup?x=1', '1'),
        '/1/nfse/dps/lookup?x=1&number=1');
    for (const unsafe of ['0', '-1', '2/foo', '0002', 'a', '9'.repeat(16), '1&x=2']) {
        assert.throws(() => dps.lookupUrl('/1/nfse/dps/lookup', unsafe));
    }
});

test('Readiness data never declares external fiscal attestation', () => {
    const result = dps.summarize({
        mechanically_ready: true, candidate_number: '900000000000008', reasons: [],
    });
    assert.equal(result.mechanicallyReady, true);
    assert.equal(result.candidate, '900000000000008');
    assert.equal(result.externallyAttested, false);
    assert.equal(dps.summarize({
        mechanically_ready: false, candidate_number: null, reasons: ['series_inactive'],
    }).candidate, null);
});

test('DPS diagnostics issue GET JSON only, with browser-session credentials', async () => {
    const calls = [];
    const data = await dps.readJson(async (url, options) => {
        calls.push({ url, options });
        return { ok: true, json: async () => ({ data: { official_state: 'not_found', safe_to_emit: false } }) };
    }, '/1/nfse/dps/lookup?number=900000000000008');
    assert.equal(data.safe_to_emit, false);
    assert.equal(calls[0].options.method, 'GET');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    await assert.rejects(() => dps.readJson(async () => ({ ok: true, json: async () => ({ html: 'not an API' }) }), '/foo'));
    await assert.rejects(() => dps.readJson(async () => ({ ok: false }), '/foo'));
});
