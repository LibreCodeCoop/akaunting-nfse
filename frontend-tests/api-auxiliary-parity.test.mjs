// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { init: initFooter } = require('../Resources/assets/js/email-footer-admin.js');
const { init: initRefreshAll } = require('../Resources/assets/js/refresh-all-browser.js');

function element(dataset = {}) {
    const listeners = {};
    return {
        dataset, listeners, value: '', textContent: '', disabled: false, hidden: true,
        focused: false, attributes: {},
        addEventListener(name, callback) { listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        focus() { this.focused = true; },
    };
}

function documentFor(elements) {
    return { getElementById(id) { return elements[id] ?? null; } };
}

const response = (data, ok = true) => ({
    ok, json: async () => data,
});

test('footer loads only from JSON and saves via PATCH with same-origin CSRF', async () => {
    const form = element({
        readUrl: '/1/nfse/settings/email-footer',
        writeUrl: '/1/nfse/settings/email-footer',
        csrfToken: 'synthetic-csrf',
        savedMessage: 'Saved',
        errorMessage: 'Invalid footer',
    });
    const message = element();
    const url = element();
    const feedback = element();
    const save = element();
    const calls = [];
    const fetchMock = async (path, options) => {
        calls.push({ path, options });
        if (options.method === 'GET') {
            return response({ data: { footer_message: 'Existing footer', footer_url: 'https://example.test/info' } });
        }
        return response({ data: JSON.parse(options.body) });
    };
    initFooter(documentFor({
        'nfse-email-footer-form': form,
        'nfse-email-footer-message': message,
        'nfse-email-footer-url': url,
        'nfse-email-footer-feedback': feedback,
        'nfse-email-footer-save': save,
    }), fetchMock);

    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(message.value, 'Existing footer');
    assert.equal(save.disabled, false);
    assert.equal(calls[0].options.method, 'GET');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    message.value = 'Updated by JSON';
    url.value = 'https://example.test/new';
    let prevented = false;
    await form.listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(calls[1].options.method, 'PATCH');
    assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
    assert.deepEqual(JSON.parse(calls[1].options.body), {
        footer_message: 'Updated by JSON', footer_url: 'https://example.test/new',
    });
    assert.equal(feedback.textContent, 'Saved');
    assert.equal(save.disabled, false);
});

test('footer refuses edits when the read API fails', async () => {
    const form = element({ readUrl: '/settings/email-footer', errorMessage: 'Failed' });
    const save = element();
    const feedback = element();
    initFooter(documentFor({
        'nfse-email-footer-form': form,
        'nfse-email-footer-message': element(),
        'nfse-email-footer-url': element(),
        'nfse-email-footer-feedback': feedback,
        'nfse-email-footer-save': save,
    }), async () => response({ message: 'Unauthorized' }, false));
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(save.disabled, true);
    assert.equal(feedback.textContent, 'Failed');
    assert.equal(feedback.focused, true);
});

test('refresh-all sends a JSON POST and reloads only on confirmed completion', async () => {
    const form = element({
        refreshUrl: '/1/nfse/invoices/refresh-all',
        csrfToken: 'synthetic-csrf',
        loadingLabel: 'Refreshing',
        errorMessage: 'Failed',
    });
    const button = element();
    button.textContent = 'Refresh';
    const feedback = element();
    let reloads = 0;
    const calls = [];
    initRefreshAll(documentFor({
        'refresh-all-form': form,
        'index-more-actions-refresh-nfse-invoices': button,
        'nfse-refresh-all-feedback': feedback,
    }), async (path, options) => {
        calls.push({ path, options });
        return response({ success: true, message: 'Updated 3 documents', updated: 3, failed: 0 });
    }, () => { reloads++; });
    let prevented = false;
    await form.listeners.submit({ preventDefault() { prevented = true; } });

    assert.equal(prevented, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].path, '/1/nfse/invoices/refresh-all');
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
    assert.equal(feedback.textContent, 'Updated 3 documents');
    assert.equal(reloads, 1);
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Refresh');
});

test('refresh-all reports partial failures without a false successful reload', async () => {
    const form = element({ refreshUrl: '/1/nfse/invoices/refresh-all', csrfToken: 'test', errorMessage: 'Failed' });
    const button = element();
    const feedback = element();
    let reloads = 0;
    initRefreshAll(documentFor({
        'refresh-all-form': form,
        'index-more-actions-refresh-nfse-invoices': button,
        'nfse-refresh-all-feedback': feedback,
    }), async () => response({ success: false, status: 'warning', message: '1 failed', updated: 2, failed: 1 }), () => { reloads++; });

    await form.listeners.submit({ preventDefault() {} });
    assert.equal(feedback.textContent, '1 failed');
    assert.equal(feedback.focused, true);
    assert.equal(reloads, 0);
});
