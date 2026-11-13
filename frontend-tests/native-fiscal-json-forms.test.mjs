// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const fiscal = require('../Resources/assets/js/native-fiscal-json-forms.js');

function fixture() {
    const button = { disabled: false };
    const nodes = [];
    const form = {
        action: '/1/nfse/invoices/7/fiscal-decision',
        dataset: { nfseJsonContract: 'decision' },
        ownerDocument: {
            createElement() {
                return {
                    setAttribute(name, value) { this[name] = value; },
                    textContent: '',
                };
            },
        },
        querySelector(selector) {
            if (selector === 'button[type="submit"]') return button;
            if (selector === '[data-nfse-fiscal-json-feedback]') return nodes[0] ?? null;
            return null;
        },
        appendChild(node) { nodes.push(node); },
    };
    return { form, button, nodes };
}

test('decision POST uses same-origin credentials and JSON response, retaining native CSRF form data', async () => {
    const { form, button, nodes } = fixture();
    const data = { _token: 'SYNTHETIC_CSRF', decision: 'do_not_issue' };
    form.dataset.nfseJsonSuccess = 'Decision saved in synthetic locale';
    let sent;
    let reloaded = 0;
    const result = await fiscal.submit(form, {
        formDataFactory: () => data,
        fetchImpl: async (url, options) => {
            sent = { url, options };
            return { status: 201, async json() { return { data: { decision: 'do_not_issue' } }; } };
        },
        reload: () => { reloaded += 1; },
    });
    assert.equal(result.kind, 'success');
    assert.equal(sent.url, form.action);
    assert.equal(sent.options.method, 'POST');
    assert.equal(sent.options.credentials, 'same-origin');
    assert.equal(sent.options.headers.Accept, 'application/json');
    assert.equal(sent.options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(sent.options.body, data);
    assert.equal(data._token, 'SYNTHETIC_CSRF');
    assert.equal(reloaded, 1);
    assert.equal(button.disabled, true);
    assert.equal(nodes[0].role, 'alert');
    assert.equal(nodes[0].textContent, 'Decision saved in synthetic locale');
});

test('fiscal event attestation accepts its distinct JSON result', async () => {
    const { form } = fixture();
    form.dataset.nfseJsonContract = 'evidence';
    const result = await fiscal.submit(form, {
        formDataFactory: () => ({ acknowledge: '1' }),
        fetchImpl: async () => ({ status: 201, async json() { return { status: 'attested' }; } }),
        reload() {},
    });
    assert.equal(result.kind, 'success');
});

test('known validation and authorization failures allow correction without automatic retry', async () => {
    for (const status of [401, 403, 419, 422]) {
        const { form, button, nodes } = fixture();
        let calls = 0;
        const result = await fiscal.submit(form, {
            formDataFactory: () => ({}),
            fetchImpl: async () => {
                calls += 1;
                return { status, async json() { return { message: 'Synthetic rejection' }; } };
            },
        });
        assert.deepEqual(result, { kind: 'rejected', status });
        assert.equal(button.disabled, false);
        assert.equal(form.dataset.nfseSubmitting, undefined);
        assert.equal(calls, 1);
        assert.ok(nodes[0].textContent.length > 5);
    }
});

test('network errors and malformed JSON are uncertain and block a second submission', async () => {
    for (const failKind of ['network', 'non-json']) {
        const { form, button, nodes } = fixture();
        let calls = 0;
        const fetchImpl = async () => {
            calls += 1;
            if (failKind === 'network') throw new Error('lost connection after processing');
            return { status: 200, async json() { throw new Error('HTML redirect'); } };
        };
        assert.equal((await fiscal.submit(form, { formDataFactory: () => ({}), fetchImpl })).kind, 'uncertain');
        assert.equal((await fiscal.submit(form, { formDataFactory: () => ({}), fetchImpl })).kind, 'already-submitted');
        assert.equal(calls, 1);
        assert.equal(button.disabled, true);
        assert.equal(form.dataset.nfseSubmitting, 'uncertain');
        assert.match(nodes[0].textContent, /check the history/i);
    }
});

test('concurrent form submits send exactly one POST', async () => {
    const { form } = fixture();
    let calls = 0;
    let unblock;
    const pending = new Promise((resolve) => { unblock = resolve; });
    const options = {
        formDataFactory: () => ({}),
        fetchImpl: async () => {
            calls += 1;
            await pending;
            return { status: 422, async json() { return { message: 'invalid' }; } };
        },
    };
    const first = fiscal.submit(form, options);
    assert.equal((await fiscal.submit(form, options)).kind, 'already-submitted');
    unblock();
    assert.equal((await first).kind, 'rejected');
    assert.equal(calls, 1);
});

test('boot delegates only explicitly marked fiscal forms and leaves HTML fallback intact', async () => {
    const { form } = fixture();
    const handlers = {};
    const panel = {
        dataset: {},
        addEventListener(name, callback) { handlers[name] = callback; },
    };
    const documentRef = {
        querySelector() { return panel; },
    };
    let calls = 0;
    assert.equal(fiscal.boot(documentRef, { formDataFactory: () => ({}), fetchImpl: async () => {
        calls += 1;
        return { status: 422, async json() { return {}; } };
    } }), true);
    assert.equal(fiscal.boot(documentRef), false);
    let prevented = false;
    handlers.submit({ target: { matches: () => false }, preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    form.matches = (selector) => selector === 'form[data-nfse-fiscal-json-form="true"]';
    handlers.submit({ target: form, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(calls, 1);
});

test('a 201 with another operation contract is uncertain, not reported as saved', async () => {
    const { form, button } = fixture();
    const result = await fiscal.submit(form, {
        formDataFactory: () => ({}),
        fetchImpl: async () => ({ status: 201, async json() { return { status: 'attested' }; } }),
    });
    assert.equal(result.kind, 'uncertain');
    assert.equal(button.disabled, true);
});
