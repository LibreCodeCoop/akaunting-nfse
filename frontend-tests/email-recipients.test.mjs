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
