// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const browser = require('../Resources/assets/js/adn-distribution-browser.js');

test('ADN query parameters are normalized deterministically', () => {
    assert.equal(
        browser.buildQuery(' 15 ', ' 11222333000181 ', true),
        'nsu=15&cnpj=11222333000181&lote=1',
    );
    assert.equal(
        browser.buildQuery('', '', false),
        'nsu=0&cnpj=&lote=0',
    );
});

test('ADN summary keeps reconciliation defaults explicit', () => {
    assert.deepEqual(
        browser.summarize({
            status_processamento: 'OK',
            ultimo_nsu: 22,
            documents: [{}, {}, {}],
            reconciliation: { matched: 2 },
        }),
        {
            status: 'OK',
            documents: '3',
            matched: '2',
            unmatched: '3',
            lastNsu: '22',
        },
    );
});

test('ADN summary handles empty response safely', () => {
    assert.deepEqual(
        browser.summarize({}),
        {
            status: '—',
            documents: '0',
            matched: '0',
            unmatched: '0',
            lastNsu: '—',
        },
    );
});
