// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { lcForNational, debounce, createSearch, populateNationalOptions, loadNationalOptions } = require('../Resources/assets/js/tax-code-assistant.js');

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

test('native Akaunting Vue select receives chosen value and reports form change', () => {
    const notifications = [];
    const component = {
        selected: '',
        sorted_options: [],
        full_options: [],
        change() { notifications.push(this.selected); },
    };
    const field = { __vue__: component };
    assert.equal(require('../Resources/assets/js/tax-code-assistant.js').selectValue(field, '010701', '010701 - Test'), true);
    assert.equal(component.selected, '010701');
    assert.equal(component.sorted_options[0].key, '010701');
    assert.equal(component.full_options[0].key, '010701');
    assert.deepEqual(notifications, ['010701']);
    assert.equal(require('../Resources/assets/js/tax-code-assistant.js').selectValue({}, '010701', ''), false);
});

test('native Akaunting selection events trigger assistant listeners', () => {
    const { listenToSelect } = require('../Resources/assets/js/tax-code-assistant.js');
    const callbacks = {};
    const field = {
        __vue__: {
            selected: '',
            sorted_options: [],
            change() {},
            $on(event, callback) { callbacks[event] = callback; },
        },
        addEventListener() { throw new Error('Vue custom select must use Vue events'); },
    };
    let updates = 0;
    listenToSelect(field, () => { updates += 1; });
    callbacks.change();
    assert.equal(updates, 1);
});

test('resolves native Akaunting select component through nested rendered inputs', () => {
    const { selectComponent } = require('../Resources/assets/js/tax-code-assistant.js');
    const component = { selected: '010701', sorted_options: [], change() {} };
    const host = { __vue__: { $parent: component }, parentElement: null };
    const field = { parentElement: host };
    assert.equal(selectComponent(field), component);
});

test('JSON catalog fills native select without overwriting a saved or in-progress selection', () => {
    const component = {
        selected: '010701',
        sorted_options: [{ key: '010701', value: '010701', option: { key: '010701', value: '010701' } }],
        full_options: [{ key: '010701', value: '010701', option: { key: '010701', value: '010701' } }],
        change() { throw new Error('Catalog loading must not change user selections'); },
    };
    const field = { __vue__: component };
    const entries = [
        { code: '010701', description: 'Serviço de suporte' },
        { code: '010601', description: 'Assessoria e consultoria' },
        { code: '<script>', description: 'Invalid' },
    ];

    assert.equal(populateNationalOptions(field, entries), true);
    assert.equal(populateNationalOptions(field, entries), true);
    assert.equal(component.selected, '010701');
    for (const options of [component.sorted_options, component.full_options]) {
        assert.equal(options.length, 2);
        assert.equal(options.find(item => item.key === '010701').value, '010701 - Serviço de suporte');
        assert.ok(options.some(item => item.key === '010601'));
    }
    assert.equal(populateNationalOptions({}, entries), false);
});

test('catalog loading uses same-origin JSON endpoint and rejects invalid responses', async () => {
    const requests = [];
    const component = { selected: '', sorted_options: [], full_options: [], change() {} };
    const field = { __vue__: component };
    const fetcher = async (url, options) => {
        requests.push({ url, options });
        return { ok: true, json: async () => ({ data: [{ code: '010101', description: 'Development' }] }) };
    };

    await loadNationalOptions('/1/nfse/national-services', field, fetcher);
    assert.equal(requests[0].url, '/1/nfse/national-services?limit=500');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    assert.equal(requests[0].options.headers.Authorization, undefined);
    assert.equal(component.sorted_options[0].key, '010101');

    await assert.rejects(
        loadNationalOptions('/1/nfse/national-services', field, async () =>
            ({ ok: true, json: async () => ({ html: '<div>not a catalog</div>' }) })),
        /Invalid catalog response/,
    );
    await assert.rejects(
        loadNationalOptions('/1/nfse/national-services', field, async () => ({ ok: false })),
        /Catalog loading failed/,
    );
});
