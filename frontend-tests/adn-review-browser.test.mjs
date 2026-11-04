// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const review = require('../Resources/assets/js/adn-review-browser.js');

test('ADN review builds only numeric document URLs', () => {
    assert.equal(
        review.urlFor('/1/nfse/adn/review/__DOCUMENT_ID__/import', '123'),
        '/1/nfse/adn/review/123/import',
    );
    assert.throws(() => review.urlFor('/1/nfse/adn/review/__DOCUMENT_ID__/import', '../2'));
    assert.throws(() => review.urlFor('/1/nfse/adn/review/__DOCUMENT_ID__/import', 0));
    assert.throws(() => review.urlFor('https://api.example.test/adn', 1));
});

test('ADN review never renders issued or unknown fiscal documents as bills', () => {
    const rows = [
        { id: 1, fiscal_role: 'received', review_status: 'pending' },
        { id: 2, fiscal_role: 'intermediated', review_status: 'pending' },
        { id: 3, fiscal_role: 'emitted', review_status: 'pending' },
        { id: 4, fiscal_role: 'event', review_status: 'pending' },
        { id: 5, fiscal_role: 'unknown', review_status: 'pending' },
        { id: 6, fiscal_role: 'received', review_status: 'imported' },
        { id: '../2', fiscal_role: 'received', review_status: 'pending' },
    ];
    assert.deepEqual(review.eligibleRows(rows).map((row) => row.id), [1, 2]);
    assert.deepEqual(review.eligibleRows({}), []);
});

test('ADN browser sends only session credentials for read requests and rejects JSON errors', async () => {
    const requests = [];
    const data = await review.getJson(async (url, options) => {
        requests.push({ url, options });
        return { ok: true, json: async () => ({ data: [{ id: 1 }] }) };
    }, '/1/nfse/adn/documents');
    assert.deepEqual(data, [{ id: 1 }]);
    assert.deepEqual(requests, [{
        url: '/1/nfse/adn/documents',
        options: {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        },
    }]);
    await assert.rejects(
        review.getJson(async () => ({ ok: false, json: async () => ({ message: 'Forbidden' }) }), '/x'),
        /Forbidden/,
    );
});
