// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { lcForNational, debounce, createSearch } = require('../Resources/assets/js/tax-code-assistant.js');

test('national code identifies a LC 116 subitem', () => {
    assert.equal(lcForNational('010701'), 'lc:0107');
    assert.equal(lcForNational('010702'), 'lc:0107');
    assert.equal(lcForNational('123'), '');
});

test('debounce only executes the latest search', () => {
    const jobs = new Map();
    let id = 0;
    const received = [];
    const schedule = fn => { jobs.set(++id, fn); return id; };
    const cancel = key => jobs.delete(key);
    const delayed = debounce(value => received.push(value), 300, schedule, cancel);
    delayed('first');
    delayed('second');
    delayed('last');
    assert.equal(jobs.size, 1);
    [...jobs.values()][0]();
    assert.deepEqual(received, ['last']);
});

test('stale search responses cannot replace the latest result', async () => {
    const pending = [];
    const fetcher = (url, options) => new Promise(resolve => pending.push({ url, options, resolve }));
    const search = createSearch(fetcher);
    const a = search('/api', { q: 'old' });
    const b = search('/api', { q: 'new' });
    assert.equal(pending[0].options.signal.aborted, true);
    pending[1].resolve({ ok: true, json: async () => ({ data: [{ code: '010702' }] }) });
    pending[0].resolve({ ok: true, json: async () => ({ data: [{ code: '010701' }] }) });
    assert.deepEqual(await b, [{ code: '010702' }]);
    assert.equal(await a, null);
});

test('search refuses unsuccessful server responses', async () => {
    const search = createSearch(async () => ({ ok: false }));
    await assert.rejects(search('/api', { q: 'test' }), /Search failed/);
});
