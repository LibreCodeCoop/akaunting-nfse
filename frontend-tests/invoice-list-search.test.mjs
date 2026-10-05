// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const listSearch = require('../Resources/assets/js/invoice-list-search.js');

test('clear search preserves the list path and makes the empty search explicit', () => {
    assert.equal(
        listSearch.clearSearchUrl('https://example.test/1/nfse/invoices?status=pending&search=abc'),
        'https://example.test/1/nfse/invoices?search=',
    );
});

test('search cookie state is scoped by the current list path', () => {
    assert.deepEqual(
        listSearch.mergeSearchCookie(
            'https://example.test/1/nfse/invoices?status=pending',
            {'https://example.test/1/other': {status: 'paid'}},
            {status: ['pending']},
        ),
        {
            'https://example.test/1/other': {status: 'paid'},
            'https://example.test/1/nfse/invoices': {status: ['pending']},
        },
    );
});

test('invalid search cookie JSON falls back to an empty map', () => {
    assert.deepEqual(listSearch.parseCookieValue('{invalid'), {});
    assert.deepEqual(listSearch.parseCookieValue(''), {});
});
