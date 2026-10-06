// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const require = createRequire(import.meta.url);
const moduleApi = require('../Resources/assets/js/native-invoice-list-status.js');

test('browser asset contains the same implementation exercised by unit tests', () => {
    const source = new URL('../Resources/assets/js/native-invoice-list-status.js', import.meta.url);
    const browserAsset = new URL('../Resources/assets/js/native-invoice-list-status.min.js', import.meta.url);

    assert.equal(readFileSync(browserAsset, 'utf8'), readFileSync(source, 'utf8'));
});

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


test('selectedInvoiceIds returns unique checked native invoice ids', () => {
    const root = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-bulk-action]:checked');

            return [
                { value: '9', dataset: { bulkAction: '9' } },
                { value: '11', dataset: { bulkAction: '11' } },
                { value: '9', dataset: { bulkAction: '9' } },
                { value: 'not-an-id', dataset: {} },
            ];
        },
    };

    assert.deepEqual(moduleApi.selectedInvoiceIds(root), [9, 11]);
});


test('delegated bulk selection sync survives checkbox replacement', () => {
    const listeners = {};
    let syncCalls = 0;
    const root = {
        addEventListener(name, listener, capture) {
            listeners[name] = { listener, capture };
        },
    };

    assert.equal(moduleApi.bindBulkSelectionSync(root, () => {
        syncCalls += 1;
    }), true);

    const currentCheckbox = {
        matches(selector) {
            return selector === '[data-bulk-action]';
        },
    };

    listeners.change.listener({ target: currentCheckbox });
    assert.equal(syncCalls, 1);

    moduleApi.bindBulkSelectionSync(root, () => {
        syncCalls += 10;
    });
    listeners.input.listener({ target: currentCheckbox });

    assert.equal(syncCalls, 11);
    assert.equal(Object.keys(listeners).length, 2);
    assert.equal(listeners.change.capture, true);
    assert.equal(listeners.input.capture, true);
});


test('delegated bulk sync runs again after the UI frame', async () => {
    const listeners = {};
    let scheduled = null;
    let calls = 0;
    const root = {
        defaultView: {
            requestAnimationFrame(callback) {
                scheduled = callback;
            },
        },
        addEventListener(name, listener, capture) {
            listeners[name] = { listener, capture };
        },
    };

    moduleApi.bindBulkSelectionSync(root, () => {
        calls += 1;
    });

    listeners.change.listener({
        target: {
            matches(selector) {
                return selector === '[data-bulk-action]';
            },
        },
    });

    assert.equal(calls, 1);
    assert.equal(typeof scheduled, 'function');

    scheduled();

    assert.equal(calls, 2);
});


test('bulk selection observer resyncs after Vue replaces table content', () => {
    let callback = null;
    let observed = null;
    let syncCalls = 0;

    class FakeMutationObserver {
        constructor(handler) {
            callback = handler;
        }

        observe(target, options) {
            observed = { target, options };
        }
    }

    const body = {};
    const root = {
        body,
        defaultView: {
            MutationObserver: FakeMutationObserver,
        },
    };

    const observer = moduleApi.observeBulkSelection(root, () => {
        syncCalls += 1;
    });

    assert.ok(observer);
    assert.equal(observed.target, body);
    assert.deepEqual(observed.options, { childList: true, subtree: true });

    callback([]);

    assert.equal(syncCalls, 1);
});


test('mountBulkDispatch binds current checkboxes even when delegated sync is available', () => {
    const checkboxListeners = {};
    const checkbox = {
        checked: false,
        value: '9',
        dataset: { bulkAction: '9' },
        addEventListener(name, listener) {
            checkboxListeners[name] = listener;
        },
        closest(selector) {
            if (selector !== 'table') {
                return null;
            }

            return {
                parentNode: {
                    insertBefore() {},
                },
            };
        },
    };
    const button = {
        disabled: false,
        classList: { add() {} },
    };
    const form = {
        dataset: {},
        classList: { add() {} },
        appendChild() {},
        addEventListener() {},
        querySelectorAll() {
            return [];
        },
    };
    const root = {
        body: {},
        defaultView: {
            MutationObserver: class {
                observe() {}
            },
        },
        addEventListener() {},
        querySelector(selector) {
            return selector === '[data-nfse-bulk-dispatch-form]' ? null : null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-bulk-action]') {
                return [checkbox];
            }

            if (selector === '[data-bulk-action]:checked') {
                return checkbox.checked ? [checkbox] : [];
            }

            return [];
        },
        createElement(tag) {
            if (tag === 'form') {
                return form;
            }

            if (tag === 'button') {
                return button;
            }

            return {
                dataset: {},
            };
        },
    };

    moduleApi.mountBulkDispatch(root, { url: '/bulk' });

    assert.equal(typeof checkboxListeners.change, 'function');
    assert.equal(typeof checkboxListeners.input, 'function');

    checkbox.checked = true;
    checkboxListeners.change();

    assert.equal(button.disabled, false);
});
