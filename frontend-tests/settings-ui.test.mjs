// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const settings = require('../Resources/assets/js/settings-ui.js');

test('settings tab navigation wraps and supports home/end', () => {
    assert.equal(settings.nextTabIndex(0, 3, 'ArrowLeft'), 2);
    assert.equal(settings.nextTabIndex(2, 3, 'ArrowRight'), 0);
    assert.equal(settings.nextTabIndex(2, 3, 'Home'), 0);
    assert.equal(settings.nextTabIndex(0, 3, 'End'), 2);
});

test('artifact persistence requires at least one selected format', () => {
    assert.equal(settings.artifactsCanSave('0', '0'), false);
    assert.equal(settings.artifactsCanSave('1', '0'), true);
    assert.equal(settings.artifactsCanSave('0', '1'), true);
});

test('federal dependent states follow retention and situation contracts', () => {
    assert.equal(settings.retentionShowsCsll('3'), true);
    assert.equal(settings.retentionShowsCsll('9'), true);
    assert.equal(settings.retentionShowsCsll('2'), false);
    assert.equal(settings.situationBlocksPiscofins('4'), true);
    assert.equal(settings.situationBlocksPiscofins('6'), true);
    assert.equal(settings.situationBlocksPiscofins('1'), false);
});

test('municipal query uses normalized explicit parameters', () => {
    assert.deepEqual(
        settings.buildMunicipalQuery(' 3303302 ', ' 0107 ', ' 2026-10-05 '),
        {
            municipio_ibge: '3303302',
            service_code: '0107',
            competence: '2026-10-05',
        },
    );
});
