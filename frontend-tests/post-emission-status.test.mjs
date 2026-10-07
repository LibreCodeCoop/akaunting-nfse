// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const status = require('../Resources/assets/js/post-emission-status.js');

test('post-emission polling uses bounded backoff instead of a fixed hot loop', () => {
    assert.equal(status.nextPollDelay(0), 2_000);
    assert.equal(status.nextPollDelay(14_999), 2_000);
    assert.equal(status.nextPollDelay(15_000), 5_000);
    assert.equal(status.nextPollDelay(44_999), 5_000);
    assert.equal(status.nextPollDelay(45_000), 10_000);
    assert.equal(status.MAX_POLL_DURATION_MS, 90_000);
});

test('post-emission polling only continues for active server state', () => {
    assert.equal(status.shouldPoll({ status: 'processing', poll: true }), true);
    assert.equal(status.shouldPoll({ status: 'completed', poll: false }), false);
    assert.equal(status.shouldPoll({ status: 'failed', poll: false }), false);
    assert.equal(status.shouldPoll({ status: 'processing', poll: false }), false);
});

test('active stage recognition includes retrying but not terminal states', () => {
    assert.equal(status.isActiveStatus('pending'), true);
    assert.equal(status.isActiveStatus('processing'), true);
    assert.equal(status.isActiveStatus('retrying'), true);
    assert.equal(status.isActiveStatus('completed'), false);
    assert.equal(status.isActiveStatus('failed'), false);
});


test('missing artifact is disabled even when polling already stopped', () => {
    const classes = new Set();
    const spinnerClasses = new Set();
    const label = { textContent: '' };

    const link = {
        attrs: {},
        classList: {
            add: (...values) => values.forEach((value) => classes.add(value)),
            remove: (...values) => values.forEach((value) => classes.delete(value)),
        },
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
        querySelector(selector) {
            if (selector === '[data-nfse-artifact-spinner]') {
                return {
                    classList: {
                        toggle: (value, force) => force ? spinnerClasses.add(value) : spinnerClasses.delete(value),
                    },
                };
            }

            if (selector === '[data-nfse-artifact-label]') {
                return label;
            }

            return null;
        },
    };

    const root = {
        dataset: {
            artifactMissing: 'Unavailable',
            artifactProcessing: 'Processing',
        },
        querySelector(selector) {
            return selector === '[data-nfse-artifact="xml"]' ? link : null;
        },
    };

    status.setArtifactState(root, 'xml', { ready: false, download_url: null }, false);

    assert.equal(link.attrs['aria-disabled'], 'true');
    assert.equal(classes.has('pointer-events-none'), true);
    assert.equal(label.textContent, 'Unavailable');
    assert.equal(spinnerClasses.has('hidden'), true);
});

