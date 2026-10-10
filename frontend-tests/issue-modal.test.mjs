// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const modal = require('../Resources/assets/js/issue-modal.js');

test('keyboard tab navigation wraps and supports home/end', () => {
    assert.equal(modal.nextTabIndex(0, 3, 'ArrowLeft'), 2);
    assert.equal(modal.nextTabIndex(2, 3, 'ArrowRight'), 0);
    assert.equal(modal.nextTabIndex(1, 3, 'Home'), 0);
    assert.equal(modal.nextTabIndex(1, 3, 'End'), 2);
});

test('send-email state controls fields, columns and attachment tab', () => {
    assert.deepEqual(modal.sendEmailUiState(true), {
        fieldsHidden: false,
        navColumns: 3,
        attachmentsVisible: true,
    });

    assert.deepEqual(modal.sendEmailUiState(false), {
        fieldsHidden: true,
        navColumns: 2,
        attachmentsVisible: false,
    });
});

test('switch presentation keeps submitted value and visual state synchronized', () => {
    assert.deepEqual(modal.switchPresentation(true), {
        hiddenValue: '1',
        trackColor: '#5e9f4d',
        thumbLeft: '1.5rem',
    });
    assert.deepEqual(modal.switchPresentation(false), {
        hiddenValue: '0',
        trackColor: '#dbe8d4',
        thumbLeft: '0.25rem',
    });
});

test('restore-default comparison normalizes equivalent editor html', () => {
    assert.equal(
        modal.shouldShowRestore(
            'Subject',
            '<p>Hello<br>World</p>',
            'Subject',
            'Hello\nWorld',
            null,
        ),
        false,
    );

    assert.equal(
        modal.shouldShowRestore(
            'Subject',
            '<p>Prezado(a)</p><p><br></p><p>Segue a nota.</p>',
            'Subject',
            'Prezado(a)<br><br>Segue a nota.',
            null,
        ),
        false,
    );

    assert.equal(
        modal.shouldShowRestore('Changed', '<p>Hello</p>', 'Subject', 'Hello', null),
        true,
    );
});


test('error summary receives focus once when visible', () => {
    let focusCount = 0;
    const summary = {
        __nfseErrorSummaryFocused: false,
        offsetParent: {},
        focus() {
            focusCount += 1;
        },
    };
    const root = {
        querySelector(selector) {
            return selector === '[data-nfse-error-summary="true"]' ? summary : null;
        },
    };

    assert.equal(modal.focusErrorSummary(root), true);
    assert.equal(focusCount, 1);
    assert.equal(modal.focusErrorSummary(root), false);
    assert.equal(focusCount, 1);
});

test('native fiscal action delegates only to the dedicated compiled NFS-e modal trigger', () => {
    let clicked = 0;
    const trigger = {
        click() {
            clicked += 1;
        },
    };
    const documentRef = {
        querySelector(selector) {
            return selector === '[data-nfse-native-modal-trigger="true"]' ? trigger : null;
        },
    };

    assert.equal(modal.triggerNativeDocumentModal(documentRef), true);
    assert.equal(clicked, 1);
});

test('native fiscal action does not fall back to Akaunting customer-email actions', () => {
    let nativeSendClicked = 0;
    const documentRef = {
        querySelector() {
            return null;
        },
        getElementById(id) {
            if (id === 'show-slider-actions-send-email-invoice' || id === 'show-more-actions-send-email-invoice') {
                return {
                    click() {
                        nativeSendClicked += 1;
                    },
                };
            }

            return null;
        },
    };

    assert.equal(modal.triggerNativeDocumentModal(documentRef), false);
    assert.equal(nativeSendClicked, 0);
});


test('hydration reconciliation reapplies active tab state', () => {
    let tabSyncs = 0;
    const activePane = { id: 'nfse-tab-pane-email', style: { display: 'none' }, setAttribute() {} };
    const inactivePane = { id: 'nfse-tab-pane-issuance', style: { display: '' }, setAttribute() {} };
    const activeNav = {
        getAttribute() {
            return 'nfse-tab-pane-email';
        },
    };
    const container = {
        querySelector(selector) {
            return selector.includes('.active-tabs') ? activeNav : null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-nfse-tab-pane]') {
                return [inactivePane, activePane];
            }
            return [];
        },
    };
    const documentRef = {
        querySelectorAll(selector) {
            if (selector === '[data-nfse-tabs]') {
                tabSyncs += 1;
                return [container];
            }
            return [];
        },
        getElementById() {
            return null;
        },
    };

    assert.equal(modal.reconcileHydratedModal(documentRef), 1);
    assert.equal(tabSyncs, 1);
    assert.equal(activePane.style.display, '');
    assert.equal(inactivePane.style.display, 'none');
});


test('hydration observer work is scoped to NFS-e modal mutations by selector contract', () => {
    const source = modal.boot.toString();

    assert.match(source, /closest\('\[data-nfse-tabs\]'\)/);
    assert.match(source, /data-nfse-error-summary/);
    assert.doesNotMatch(source, /new MutationObserverRef\(\(\) => \{\s*reconcileHydratedModal/);
});


test('generic hydration reconciliation does not override restore-default visibility', () => {
    const source = modal.reconcileHydratedModal.toString();

    assert.doesNotMatch(source, /syncRestoreButton/);
});


test('editor event reconciliation runs after component handlers', () => {
    const source = modal.boot.toString();

    assert.doesNotMatch(source, /\}, true\);\s*\}\);/);
});

test('substitution AJAX handles success, rejection, duplicate and transport uncertainty', async () => {
    const previousFetch = globalThis.fetch;
    const previousFormData = globalThis.FormData;
    const previousWindow = globalThis.window;
    const listeners = {};
    const messages = [];
    const button = { disabled: false };
    const form = {
        action: '/nfse/substitute',
        dataset: {},
        matches: (selector) => selector === '[data-nfse-substitution-form="true"]',
        querySelector: (selector) => selector === 'button[type="submit"]' ? button : null,
        appendChild: (message) => messages.push(message),
    };
    const doc = {
        addEventListener(type, callback) { listeners[type] = callback; },
        createElement() { return { dataset: {}, setAttribute() {}, textContent: '' }; },
        querySelectorAll() { return []; },
        getElementById() { return null; },
        defaultView: {},
    };
    let prevented = 0;
    let requests = 0;
    const navigations = [];
    const event = { target: form, preventDefault() { prevented++; } };
    globalThis.FormData = class { constructor(received) { assert.equal(received, form); } };
    globalThis.window = { location: { assign: (url) => navigations.push(url) } };
    try {
        modal.boot(doc);
        assert.equal(typeof listeners.submit, 'function');
        globalThis.fetch = async (url, options) => {
            requests++;
            assert.equal(url, form.action);
            assert.equal(options.method, 'POST');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
            return { ok: true, json: async () => ({ success: true, redirect: '/invoice/1' }) };
        };
        await listeners.submit(event);
        assert.equal(requests, 1);
        assert.deepEqual(navigations, ['/invoice/1']);
        assert.equal(button.disabled, false);

        globalThis.fetch = async () => {
            requests++;
            return { ok: true, json: async () => ({ success: false, message: 'Rejeição fiscal' }) };
        };
        await listeners.submit(event);
        assert.equal(navigations.length, 1);
        assert.equal(messages.at(-1).textContent, 'Rejeição fiscal');

        form.dataset.nfseSubmitting = 'true';
        await listeners.submit(event);
        assert.equal(requests, 2);
        form.dataset.nfseSubmitting = 'false';

        globalThis.fetch = async () => { requests++; throw new Error('connection interrupted'); };
        await listeners.submit(event);
        assert.equal(requests, 3);
        assert.match(messages.at(-1).textContent, /Resultado não confirmado/);
        assert.equal(navigations.length, 1);
        assert.equal(button.disabled, false);
        assert.equal(prevented, 4);

        const unrelated = { target: { matches: () => false }, preventDefault: () => assert.fail('not a fiscal form') };
        await listeners.submit(unrelated);
        assert.equal(requests, 3);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.FormData = previousFormData;
        globalThis.window = previousWindow;
    }
});
