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

function substitutionFixture() {
    const messages = [];
    const button = { disabled: false };
    const form = {
        action: '/nfse/substitute',
        dataset: {},
        matches: (selector) => selector === '[data-nfse-substitution-form="true"]',
        querySelector: (selector) => selector === 'button[type="submit"]' ? button : null,
        appendChild(message) { messages.push(message); },
    };
    const documentRef = {
        createElement() { return { dataset: {}, setAttribute() {}, textContent: '' }; },
    };
    let prevented = 0;
    const event = { target: form, preventDefault() { prevented++; } };
    return { form, button, messages, documentRef, event, prevented: () => prevented };
}

const formDataFactory = () => 'test-body';

test('substitution request sends authenticated AJAX and returns success', async () => {
    const x = substitutionFixture();
    const result = await modal.requestSubstitution(x.form, async (url, init) => {
        assert.equal(url, x.form.action);
        assert.equal(init.method, 'POST');
        assert.equal(init.credentials, 'same-origin');
        assert.equal(init.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.equal(init.body, 'test-body');
        return { ok: true, json: async () => ({ success: true, redirect: '/invoice/1' }) };
    }, formDataFactory);
    assert.deepEqual(result, { kind: 'success', redirect: '/invoice/1' });
});

test('substitution request distinguishes rejection from uncertain responses', async () => {
    const x = substitutionFixture();
    assert.deepEqual(await modal.requestSubstitution(x.form,
        async () => ({ ok: false, json: async () => ({ error: true, message: 'E1235' }) }),
        formDataFactory), { kind: 'rejected', message: 'E1235' });
    assert.deepEqual(await modal.requestSubstitution(x.form,
        async () => ({ ok: false, json: async () => { throw new Error('not JSON'); } }),
        formDataFactory), { kind: 'uncertain' });
    assert.deepEqual(await modal.requestSubstitution(x.form,
        async () => { throw new Error('connection dropped'); }, formDataFactory), { kind: 'uncertain' });
});

test('substitution controller navigates on success and releases busy state', async () => {
    const x = substitutionFixture();
    const navigations = [];
    const ok = await modal.submitSubstitution(x.event, x.documentRef, {
        fetchImpl: async () => ({ ok: true, json: async () => ({ success: true, redirect: '/invoice/1' }) }),
        formDataFactory,
        navigate: (url) => navigations.push(url),
    });
    assert.equal(ok, true);
    assert.deepEqual(navigations, ['/invoice/1']);
    assert.equal(x.button.disabled, false);
    assert.equal(x.form.dataset.nfseSubmitting, 'false');
    assert.equal(x.prevented(), 1);
});

test('substitution controller preserves uncertain state without navigation or retry', async () => {
    const x = substitutionFixture();
    let requests = 0;
    const ok = await modal.submitSubstitution(x.event, x.documentRef, {
        fetchImpl: async () => { requests++; throw new Error('offline'); },
        formDataFactory,
        navigate: () => assert.fail('must not navigate'),
    });
    assert.equal(ok, false);
    assert.equal(requests, 1);
    assert.match(x.messages.at(-1).textContent, /Resultado não confirmado/);
    assert.equal(x.button.disabled, false);
});

test('substitution controller blocks duplicates and ignores unrelated forms', async () => {
    const x = substitutionFixture();
    x.form.dataset.nfseSubmitting = 'true';
    let requests = 0;
    const dependencies = { fetchImpl: async () => { requests++; }, formDataFactory };
    assert.equal(await modal.submitSubstitution(x.event, x.documentRef, dependencies), false);
    x.form.dataset.nfseSubmitting = 'false';
    x.form.matches = () => false;
    assert.equal(await modal.submitSubstitution(x.event, x.documentRef, dependencies), false);
    assert.equal(requests, 0);
});
