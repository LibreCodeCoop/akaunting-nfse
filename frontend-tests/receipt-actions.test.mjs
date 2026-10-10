// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const actions = require('../Resources/assets/js/receipt-actions.js');

function fixture(key = '12345678901234567890') {
    const classes = new Set();
    const feedback = {
        textContent: '',
        classList: {
            toggle(name, enabled) {
                if (enabled) classes.add(name);
                else classes.delete(name);
            },
        },
    };
    const receipt = {
        querySelector(selector) {
            if (selector === '[data-nfse-access-key]') return { textContent: key };
            if (selector === '[data-nfse-copy-feedback]') return feedback;
            return null;
        },
    };
    const button = {
        disabled: false,
        dataset: {
            nfseCopySuccess: 'Chave copiada.',
            nfseCopyError: 'Não foi possível copiar.',
        },
        closest(selector) {
            return selector === '[data-nfse-receipt-id]' ? receipt : null;
        },
    };

    return { button, receipt, feedback, classes };
}

test('copies exactly the key from the selected fiscal receipt', async () => {
    const first = fixture('111111');
    const second = fixture('222222');
    const copied = [];
    const clipboard = { async writeText(value) { copied.push(value); } };

    assert.equal(await actions.copyAccessKey(second.button, { clipboard }), true);
    assert.deepEqual(copied, ['222222']);
    assert.equal(second.feedback.textContent, 'Chave copiada.');
    assert.equal(second.classes.has('text-green-700'), true);
    assert.equal(first.feedback.textContent, '');
    assert.equal(second.button.disabled, false);
});

test('does not copy a missing or placeholder access key', async () => {
    let called = false;
    const clipboard = { writeText() { called = true; } };

    for (const value of ['', ' ', '—']) {
        const { button, feedback } = fixture(value);
        assert.equal(await actions.copyAccessKey(button, { clipboard }), false);
        assert.equal(feedback.textContent, '');
    }

    assert.equal(called, false);
});

test('clipboard rejection provides visible and accessible error feedback', async () => {
    const x = fixture();
    const clipboard = { async writeText() { throw new Error('not allowed'); } };

    assert.equal(await actions.copyAccessKey(x.button, { clipboard }), false);
    assert.equal(x.feedback.textContent, 'Não foi possível copiar.');
    assert.equal(x.classes.has('text-red-700'), true);
    assert.equal(x.button.disabled, false);
});

test('unavailable clipboard API does not attempt a copy', async () => {
    const x = fixture();

    assert.equal(await actions.copyAccessKey(x.button, { clipboard: {} }), false);
    assert.equal(x.feedback.textContent, 'Não foi possível copiar.');
    assert.equal(x.button.disabled, false);
});

test('prevents duplicate clicks while a clipboard write is pending', async () => {
    const x = fixture();
    let finish;
    let calls = 0;
    const pending = new Promise((resolve) => { finish = resolve; });
    const clipboard = {
        async writeText() {
            calls += 1;
            await pending;
        },
    };

    const first = actions.copyAccessKey(x.button, { clipboard });
    assert.equal(x.button.disabled, true);
    assert.equal(await actions.copyAccessKey(x.button, { clipboard }), false);
    assert.equal(calls, 1);
    finish();
    assert.equal(await first, true);
    assert.equal(x.button.disabled, false);
});

test('expanded historical disclosure changes action text and chevron direction', () => {
    const label = { textContent: '' };
    const chevron = { style: {} };
    const details = {
        open: false,
        dataset: { nfseLabelShow: 'Ver documentos', nfseLabelHide: 'Ocultar documentos' },
        querySelector(selector) {
            if (selector === '[data-nfse-disclosure-label]') return label;
            if (selector === '[data-nfse-disclosure-chevron]') return chevron;
            return null;
        },
    };

    actions.syncDisclosure(details);
    assert.equal(label.textContent, 'Ver documentos');
    assert.equal(chevron.style.transform, 'rotate(0deg)');

    details.open = true;
    actions.syncDisclosure(details);
    assert.equal(label.textContent, 'Ocultar documentos');
    assert.equal(chevron.style.transform, 'rotate(180deg)');
});

test('cancel and substitution disclosures rotate their arrows without label dependencies', () => {
    const chevron = { style: {} };
    const details = {
        open: true,
        querySelector(selector) {
            return selector === '[data-nfse-disclosure-chevron]' ? chevron : null;
        },
    };

    assert.doesNotThrow(() => actions.syncDisclosure(details));
    assert.equal(chevron.style.transform, 'rotate(180deg)');
});

test('boot delegates copy once and initializes disclosures only inside the fiscal panel', async () => {
    const x = fixture('998877');
    const listeners = {};
    const detailListeners = {};
    const label = { textContent: '' };
    const chevron = { style: {} };
    const details = {
        open: false,
        dataset: { nfseLabelShow: 'Mostrar', nfseLabelHide: 'Esconder' },
        querySelector(selector) {
            if (selector === '[data-nfse-disclosure-label]') return label;
            if (selector === '[data-nfse-disclosure-chevron]') return chevron;
            return null;
        },
        addEventListener(name, callback) { detailListeners[name] = callback; },
    };
    const panel = {
        dataset: {},
        addEventListener(name, callback) { listeners[name] = callback; },
        querySelectorAll() { return [details]; },
    };
    const documentRef = {
        querySelector(selector) {
            return selector === '[data-nfse-native-panel="true"]' ? panel : null;
        },
    };
    const copied = [];

    assert.equal(actions.boot(documentRef, {
        clipboard: { async writeText(value) { copied.push(value); } },
    }), true);
    assert.equal(actions.boot(documentRef), false);
    assert.equal(label.textContent, 'Mostrar');

    listeners.click({
        target: {
            closest(selector) {
                return selector === '[data-nfse-copy-access-key="true"]' ? x.button : null;
            },
        },
    });
    await Promise.resolve();
    assert.deepEqual(copied, ['998877']);

    details.open = true;
    detailListeners.toggle();
    assert.equal(label.textContent, 'Esconder');
    assert.equal(chevron.style.transform, 'rotate(180deg)');
});

test('boot safely ignores screens outside the native fiscal panel', () => {
    assert.equal(actions.boot(null), false);
    assert.equal(actions.boot({ querySelector: () => null }), false);
});
