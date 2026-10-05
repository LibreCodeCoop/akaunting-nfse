// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const moduleApi = require('../Resources/assets/js/native-invoice-list-status.js');

test('badge descriptor falls back to pending visual state', () => {
    const badge = moduleApi.badgeDescriptor(42, {
        status: 'unknown',
        label: 'Waiting',
        url: '/invoice/42',
    });

    assert.equal(badge.href, '/invoice/42');
    assert.equal(badge.invoiceId, '42');
    assert.equal(badge.label, 'NFS-e: Waiting');
    assert.deepEqual(badge.classes, ['bg-yellow-100', 'text-yellow-700']);
});

test('decorate appends one accessible fiscal badge and is idempotent', () => {
    const appended = [];

    const target = {
        appendChild(node) {
            appended.push(node);
        },
    };

    let alreadyDecorated = false;

    const row = {
        querySelector(selector) {
            if (selector.startsWith('[data-nfse-list-status=')) {
                return alreadyDecorated ? {} : null;
            }

            if (selector === 'span.rounded-xl.whitespace-nowrap') {
                return { parentElement: target };
            }

            return null;
        },
    };

    const checkbox = {
        closest(selector) {
            return selector === 'tr' ? row : null;
        },
    };

    const root = {
        querySelector(selector) {
            return selector === '[data-bulk-action="9"]' ? checkbox : null;
        },
        createElement(tag) {
            assert.equal(tag, 'a');

            return {
                dataset: {},
                classList: {
                    values: [],
                    add(...values) {
                        this.values.push(...values);
                    },
                },
                setAttribute(name, value) {
                    this[name] = value;
                },
            };
        },
    };

    const count = moduleApi.decorate(root, {
        9: {
            status: 'emitted',
            label: 'Emitted',
            url: '/invoice/9',
        },
    });

    assert.equal(count, 1);
    assert.equal(appended.length, 1);
    assert.equal(appended[0].href, '/invoice/9');
    assert.equal(appended[0].textContent, 'NFS-e: Emitted');
    assert.equal(appended[0]['aria-label'], 'NFS-e: Emitted');
    assert.ok(appended[0].classList.values.includes('bg-green-100'));

    alreadyDecorated = true;

    assert.equal(moduleApi.decorate(root, {
        9: {
            status: 'emitted',
            label: 'Emitted',
            url: '/invoice/9',
        },
    }), 0);
    assert.equal(appended.length, 1);
});
