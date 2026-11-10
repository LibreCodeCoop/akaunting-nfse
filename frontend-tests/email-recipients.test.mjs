// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import recipients from '../Resources/assets/js/email-recipients.js';

test('split delimited address lists without losing entries', () => {
    assert.deepEqual(recipients.splitAddresses('one@example.test, two@example.test;\nthree@example.test'), [
        'one@example.test', 'two@example.test', 'three@example.test',
    ]);
});

test('reject malformed and header-injection addresses', () => {
    for (const address of ['missing-at.test', 'bad@', 'person@example.test\r\nBcc: malicious@example.test', 'a b@example.test', '']) {
        assert.equal(recipients.isEmail(address), false, address);
    }
    assert.equal(recipients.isEmail('primary@example.test'), true);
});

test('programmatic recipient changes update visible chips when switching invoices', () => {
    const nodes = [];
    const doc = {
        createElement(tag) {
            const node = {
                tag, children: [], dataset: {}, attrs: {}, value: '', textContent: '',
                setAttribute(name, value) { this.attrs[name] = value; },
                getAttribute(name) { return this.attrs[name] || null; },
                hasAttribute(name) { return Object.hasOwn(this.attrs, name); },
                addEventListener() {},
                dispatchEvent() {},
                focus() {},
                appendChild(child) { this.children.push(child); child.parent = this; },
                append(...children) { children.forEach((child) => this.appendChild(child)); },
                insertBefore(child, before) {
                    const index = this.children.indexOf(before);
                    this.children.splice(index, 0, child);
                    child.parent = this;
                },
                insertAdjacentElement(position, child) {
                    assert.equal(position, 'afterend');
                    this.parent.insertBefore(child, this.parent.children[this.parent.children.indexOf(this) + 1]);
                },
                querySelectorAll(selector) {
                    assert.equal(selector, '[data-nfse-recipient-chip]');
                    return this.children.filter((child) => child.dataset.nfseRecipientChip);
                },
                remove() {
                    this.parent.children.splice(this.parent.children.indexOf(this), 1);
                    this.parent = null;
                },
            };
            nodes.push(node);
            return node;
        },
        querySelector() { return null; },
    };
    const parent = doc.createElement('div');
    const source = doc.createElement('input');
    source.ownerDocument = doc;
    source.id = 'cc';
    source.name = 'nfse_email_cc';
    parent.appendChild(source);
    recipients.boot({ querySelectorAll() { return [source]; } });

    recipients.replace(source, 'first@example.test, second@example.test');
    assert.equal(source.value, 'first@example.test, second@example.test');
    recipients.replace(source, 'other@example.test');
    assert.equal(source.value, 'other@example.test');
    const visible = nodes.filter((node) => node.dataset.nfseRecipientChip && node.parent);
    assert.equal(visible.length, 1);
    assert.equal(visible[0].children[0].textContent, 'other@example.test');
    recipients.replace(source, '');
    assert.equal(nodes.filter((node) => node.dataset.nfseRecipientChip && node.parent).length, 0);
    assert.equal(source.value, '');
});
