// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { buildPatch } = require('../Resources/assets/js/artifact-settings-admin.js');

test('unchanged endpoint preserves write-only credentials', () => {
    const patch = buildPatch({
        url: '', username: '', password: '',
        pathTemplate: 'nfse/{cnpj}', filenameTemplate: '{chave_acesso}',
        storeXml: true, storePdf: false, disable: false,
    });
    assert.deepEqual(patch, { nfse: {
        webdav_store_xml: true,
        webdav_store_pdf: false,
        webdav_path_template: 'nfse/{cnpj}',
        webdav_filename_template: '{chave_acesso}',
    } });
    assert.equal('webdav_password' in patch.nfse, false);
    assert.equal('webdav_url' in patch.nfse, false);
    assert.equal('webdav_username' in patch.nfse, false);
});

test('rotating credentials only submits nonempty values', () => {
    assert.deepEqual(buildPatch({
        url: ' https://dav.example.test/storage ',
        username: ' synthetic-user ',
        password: ' synthetic-secret ',
        pathTemplate: '',
        filenameTemplate: '',
        storeXml: false, storePdf: true, disable: false,
    }).nfse, {
        webdav_store_xml: false, webdav_store_pdf: true,
        webdav_path_template: '', webdav_filename_template: '',
        webdav_url: 'https://dav.example.test/storage',
        webdav_username: 'synthetic-user',
        webdav_password: 'synthetic-secret',
    });
});

test('explicit disable does not send stale write-only values', () => {
    const patch = buildPatch({
        disable: true,
        url: 'https://dav.example.test/ignored',
        username: 'ignored-user',
        password: 'ignored-password',
        pathTemplate: '', filenameTemplate: '',
        storeXml: true, storePdf: true,
    }).nfse;
    assert.equal(patch.webdav_url, '');
    assert.equal(patch.webdav_username, undefined);
    assert.equal(patch.webdav_password, undefined);
});
