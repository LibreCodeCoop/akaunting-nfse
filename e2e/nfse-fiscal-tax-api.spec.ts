// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('tax association UI reads native JSON and confirms without changing accounting taxes', async ({ page }, info) => {
    await loginToAkaunting(page, info);
    let posts = 0;
    let revisions: any[] = [];
    await page.route('**/nfse/settings/tax-associations/resources', async route => {
        await route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ data: {
                taxes: [{
                    id: 100, name: 'Synthetic Test PIS', type: 'normal', rate: '0.65',
                    enabled: true, revisions,
                }],
                identities: ['pis', 'cofins'], roles: ['provider_due', 'withheld_by_payer'],
            } }),
        });
    });
    await page.route('**/nfse/settings/tax-associations', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        expect(route.request().headers().accept).toContain('application/json');
        expect(route.request().postDataJSON()).toMatchObject({
            tax_id: '100', fiscal_identity: 'pis', fiscal_role: 'provider_due',
            acknowledge: '1',
        });
        revisions = [{
            id: 11, fiscal_identity: 'pis', fiscal_role: 'provider_due',
            effective_from: '2026-01-01', effective_until: null,
            source_reference: 'synthetic-review-evidence',
            confirmed_by: 3, confirmed_at: '2026-11-03', type_mismatch: false,
        }];
        await route.fulfill({
            status: 201, contentType: 'application/json',
            body: JSON.stringify({ data: { tax_id: 100, status: 'confirmed', already_confirmed: false } }),
        });
    });
    await page.goto('/1/nfse/settings/tax-associations', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#tax-associations-body')).toContainText('Synthetic Test PIS');
    await page.locator('#tax-association-form [name="tax_id"]').selectOption('100');
    await page.locator('#tax-association-form [name="fiscal_identity"]').selectOption('pis');
    await page.locator('#tax-association-form [name="fiscal_role"]').selectOption('provider_due');
    await page.locator('#tax-association-form [name="effective_from"]').fill('2026-01-01');
    await page.locator('#tax-association-form [name="source_reference"]').fill('synthetic-review-evidence');
    await page.locator('#tax-association-form [name="acknowledge"]').check();
    await page.locator('#tax-association-form [type="submit"]').click();
    await expect(page.locator('#tax-association-feedback')).toContainText('confirmada');
    await expect(page.locator('#tax-associations-body')).toContainText('synthetic-review-evidence');
    expect(posts).toBe(1);
    await info.attach('nfse-tax-associations-synthetic.png', {
        body: await page.locator('#nfse-tax-associations').screenshot({ animations: 'disabled' }),
        contentType: 'image/png',
    });
});

test('approximate tax sources use paginated JSON and persist a new evidence revision', async ({ page }, info) => {
    await loginToAkaunting(page, info);
    let posts = 0;
    let sources: any[] = [];
    await page.route('**/nfse/settings/approximate-taxes/resources?**', async route => {
        await route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ data: sources, meta: {
                current_page: 1, last_page: 1, per_page: 50, total: sources.length,
            } }),
        });
    });
    await page.route('**/nfse/settings/approximate-taxes', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        expect(route.request().headers().accept).toContain('application/json');
        expect(route.request().postDataJSON()).toMatchObject({
            national_service_code: '010701', source_kind: 'independent_estimate',
            source_version: 'synthetic-v1', federal_percent: '5.00',
        });
        sources = [{
            id: 20, national_service_code: '010701', simples_option: 1,
            competence_from: '2026-01-01', competence_until: null,
            source_kind: 'independent_estimate', source_name: 'Synthetic source',
            source_version: 'synthetic-v1', source_reference: 'synthetic-review-source',
            federal_percent: '5.00', state_percent: '0.00', municipal_percent: '1.00',
        }];
        await route.fulfill({
            status: 201, contentType: 'application/json',
            body: JSON.stringify({ data: { id: 20, source_version: 'synthetic-v1' } }),
        });
    });
    await page.goto('/1/nfse/settings/approximate-taxes', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#approximate-taxes-body')).toContainText('Nenhuma fonte cadastrada');
    const form = page.locator('#approximate-taxes-form');
    await form.locator('[name="national_service_code"]').fill('010701');
    await form.locator('[name="simples_option"]').selectOption('1');
    await form.locator('[name="competence_from"]').fill('2026-01-01');
    await form.locator('[name="source_kind"]').selectOption('independent_estimate');
    await form.locator('[name="source_name"]').fill('Synthetic source');
    await form.locator('[name="source_version"]').fill('synthetic-v1');
    await form.locator('[name="source_reference"]').fill('synthetic-review-source');
    await form.locator('[name="federal_percent"]').fill('5.00');
    await form.locator('[name="state_percent"]').fill('0.00');
    await form.locator('[name="municipal_percent"]').fill('1.00');
    await form.locator('[type="submit"]').click();
    await expect(page.locator('#approximate-taxes-feedback')).toContainText('registrada');
    await expect(page.locator('#approximate-taxes-body')).toContainText('synthetic-v1');
    expect(posts).toBe(1);
});
