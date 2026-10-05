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

test('native fiscal action delegates to Akaunting compiled document trigger', () => {
    let clicked = 0;
    const documentRef = {
        getElementById(id) {
            if (id !== 'show-slider-actions-send-email-invoice') {
                return null;
            }

            return {
                click() {
                    clicked += 1;
                },
            };
        },
    };

    assert.equal(modal.triggerNativeDocumentModal(documentRef), true);
    assert.equal(clicked, 1);
});

test('native fiscal action falls back to Akaunting more-actions trigger', () => {
    let clicked = 0;
    const documentRef = {
        getElementById(id) {
            if (id !== 'show-more-actions-send-email-invoice') {
                return null;
            }

            return {
                click() {
                    clicked += 1;
                },
            };
        },
    };

    assert.equal(modal.triggerNativeDocumentModal(documentRef), true);
    assert.equal(clicked, 1);
});

test('native fiscal action fails closed without compiled Akaunting trigger', () => {
    assert.equal(
        modal.triggerNativeDocumentModal({ getElementById() { return null; } }),
        false,
    );
});
