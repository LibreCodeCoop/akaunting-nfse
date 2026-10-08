// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const assistant = require('../Resources/assets/js/tax-code-assistant.js');

test('national code identifies its LC 116 subitem without inventing an association', () => {
    assert.equal(assistant.lcForNational('010701'), 'lc:0107');
    assert.equal(assistant.lcForNational('010702'), 'lc:0107');
    assert.equal(assistant.lcForNational(''), '');
    assert.equal(assistant.lcForNational('0107'), '');
});
test('one LC 116 subitem filters many national codes', () => {
    const catalog = { '010701': '010701 - Support', '010702': '010702 - Repair', '010101': '010101 - Analysis' };
    assert.deepEqual(assistant.entriesFor(catalog, 'lc:0107', '').map(([code]) => code), ['010701', '010702']);
    assert.deepEqual(assistant.entriesFor(catalog, 'lc:0107', 'REPAIR').map(([code]) => code), ['010702']);
    assert.deepEqual(assistant.entriesFor(catalog, '', '010101').map(([code]) => code), ['010101']);
    assert.deepEqual(assistant.entriesFor(catalog, 'lc:0401', '').map(([code]) => code), []);
});
test('normalization handles dotted service values without losing leading zeros', () => {
    assert.equal(assistant.digits('1.07'), '107');
    assert.equal(assistant.digits('010701'), '010701');
});

test('assistant initializes safely without fiscal fields', () => {
    let queried = 0;
    const doc = {
        querySelector(selector) {
            ++queried;
            return selector === '[data-nfse-tax-code-assistant]'
                ? { dataset: { lcOptions: '{}', nationalOptions: '{}' }, querySelector: () => null }
                : null;
        },
    };
    assistant.init(doc);
    assert.ok(queried >= 1);
});

test('assistant initialization is idempotent and does not overwrite existing choices', () => {
    const listeners = [];
    const service = { value: 'lc:0107', options: [{ value: 'lc:0107' }], addEventListener() {} };
    const code = { value: '010702', options: [{ value: '010702' }], addEventListener() {} };
    const query = { value: '', addEventListener(name, cb) { listeners.push([name, cb]); } };
    const results = { replaceChildren() {}, append() {} };
    const summary = { textContent: '' };
    const root = {
        dataset: {
            lcOptions: '{"lc:0107":"1.07 - Support"}',
            nationalOptions: '{}',
            matchesLabel: 'opções',
        },
        querySelector(selector) {
            return {
                '[data-nfse-tax-code-query]': query,
                '[data-nfse-tax-code-results]': results,
                '[data-nfse-tax-code-summary]': summary,
            }[selector];
        },
    };
    const doc = {
        querySelector(selector) {
            return {
                '[data-nfse-tax-code-assistant]': root,
                '[name="nfse_item_lista_servico"]': service,
                '[name="nfse_codigo_tributacao_nacional"]': code,
            }[selector];
        },
    };
    assistant.init(doc);
    assistant.init(doc);
    assert.equal(service.value, 'lc:0107');
    assert.equal(code.value, '010702');
    assert.equal(summary.textContent, '0 opções');
    assert.equal(listeners.length, 1);
});
