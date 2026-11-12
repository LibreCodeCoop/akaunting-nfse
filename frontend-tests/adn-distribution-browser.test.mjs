// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const browser = require('../Resources/assets/js/adn-distribution-browser.js');

test('ADN POST payload is normalized without leaking fiscal filters into URLs', () => {
    assert.deepEqual(
        browser.buildPayload(' 15 ', ' 11222333000181 ', true),
        { nsu: '15', cnpj: '11222333000181', lote: '1' },
    );
    assert.deepEqual(
        browser.buildPayload('', '', false),
        { nsu: '0', cnpj: '', lote: '0' },
    );
});

test('ADN summary keeps reconciliation defaults explicit', () => {
    assert.deepEqual(
        browser.summarize({
            status_processamento: 'OK',
            ultimo_nsu: 22,
            documents: [{}, {}, {}],
            reconciliation: { matched: 2 },
        }),
        {
            status: 'OK',
            documents: '3',
            matched: '2',
            unmatched: '3',
            lastNsu: '22',
        },
    );
});

test('ADN summary handles empty response safely', () => {
    assert.deepEqual(
        browser.summarize({}),
        {
            status: '—',
            documents: '0',
            matched: '0',
            unmatched: '0',
            lastNsu: '—',
        },
    );
});

test('ADN consultation sends one CSRF-protected JSON POST and never a side-effecting GET', async () => {
    let onClick;
    const created = (data = {}) => ({
        dataset: {}, value: '', checked: false, textContent: '',
        classList: { add() {}, remove() {} }, ...data,
    });
    const elements = Object.fromEntries([
        'adn-distribution-browser','adn-distribution-query',
        'adn-nsu','adn-cnpj','adn-lote','adn-distribution-status',
        'adn-distribution-result','adn-distribution-result-wrapper',
        'adn-distribution-summary','adn-summary-status','adn-summary-documents',
        'adn-summary-matched','adn-summary-unmatched','adn-summary-last-nsu',
    ].map(id => [id,created()]));
    elements['adn-distribution-browser'].dataset = {
        url: 'https://accounting.example.test/1/nfse/adn/distribution/consult',
        csrfToken: 'synthetic-csrf-token',
    };
    elements['adn-distribution-query'].addEventListener = (type, callback) => {
        assert.equal(type, 'click');
        onClick = callback;
    };
    elements['adn-nsu'].value = ' 4 ';
    elements['adn-cnpj'].value = ' 11222333000181 ';
    elements['adn-lote'].checked = true;

    const seen = [];
    assert.equal(browser.init({ getElementById: id => elements[id] }, async (url, options) => {
        seen.push({url,options});
        return {
            ok:true,
            json:async () => ({ data: {
                status_processamento:'PROCESSADO',ultimo_nsu:5,
                documents:[],reconciliation:{matched:0,unmatched:0},
            }}),
        };
    }),true);
    await onClick();
    assert.equal(seen.length,1);
    assert.equal(seen[0].url,'https://accounting.example.test/1/nfse/adn/distribution/consult');
    assert.equal(seen[0].options.method,'POST');
    assert.equal(seen[0].options.headers['X-CSRF-TOKEN'],'synthetic-csrf-token');
    assert.equal(seen[0].options.headers['Content-Type'],'application/json');
    assert.deepEqual(JSON.parse(seen[0].options.body),{
        nsu:'4',cnpj:'11222333000181',lote:'1',
    });
    assert.equal(elements['adn-summary-status'].textContent,'PROCESSADO');
    assert.equal(elements['adn-summary-last-nsu'].textContent,'5');
    assert.equal(elements['adn-distribution-query'].disabled,false);
});
