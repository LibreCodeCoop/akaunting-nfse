// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('artifact settings panel reads safe JSON and PATCHes without resending stored credentials', async ({ page }, info) => {
    await loginToAkaunting(page, info);

    let reads = 0;
    let writes = 0;
    const safe = {
        configured: true, host: 'dav.example.test',
        username_configured: true, password_configured: true,
        path_template: 'nfse/{cnpj}/{year}/{month}/{day}',
        filename_template: '{chave_acesso}', store_xml: true, store_pdf: true,
    };
    await page.route('**/nfse/settings/artifacts', async route => {
        const req = route.request();
        if (req.method() === 'GET') {
            reads++;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: safe }) });
            return;
        }
        expect(req.method()).toBe('PATCH');
        expect(req.headers().accept).toContain('application/json');
        expect(req.headers()['x-csrf-token']).toBeTruthy();
        const payload = req.postDataJSON();
        expect(payload.nfse).toMatchObject({
            webdav_store_xml: false,
            webdav_store_pdf: true,
            webdav_path_template: 'nfse/{cnpj}/authorized',
        });
        expect(payload.nfse.webdav_url).toBeUndefined();
        expect(payload.nfse.webdav_username).toBeUndefined();
        expect(payload.nfse.webdav_password).toBeUndefined();
        writes++;
        safe.store_xml = false;
        safe.path_template = 'nfse/{cnpj}/authorized';
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: safe }) });
    });

    await page.goto('/1/nfse/settings?tab=artifacts', { waitUntil: 'domcontentloaded' });
    const panel = page.locator('#nfse-artifact-settings');
    await expect(panel.locator('#nfse-artifact-settings-summary')).toContainText('dav.example.test');
    await expect(panel).not.toContainText('synthetic-private-credential');
    await panel.locator('#nfse-webdav-path').fill('nfse/{cnpj}/authorized');
    await panel.locator('#nfse-webdav-xml').uncheck();
    await panel.locator('#nfse-webdav-save').click();
    await expect(panel.locator('#nfse-artifact-settings-feedback')).toContainText('atualizada');
    expect(reads).toBe(1);
    expect(writes).toBe(1);
    await info.attach('nfse-webdav-safe-settings.png', {
        body: await panel.screenshot({ animations: 'disabled' }),
        contentType: 'image/png',
    });
});

test('artifact settings rejects an ACL failure without exposing stale configuration', async ({ page }, info) => {
    await loginToAkaunting(page, info);
    await page.route('**/nfse/settings/artifacts', async route => route.fulfill({
        status: 403, contentType: 'application/json', body: JSON.stringify({ reason: 'forbidden' }),
    }));
    await page.goto('/1/nfse/settings?tab=artifacts', { waitUntil: 'domcontentloaded' });
    const panel = page.locator('#nfse-artifact-settings');
    await expect(panel.locator('#nfse-artifact-settings-summary')).toContainText('Acesso negado');
    await expect(panel.locator('#nfse-webdav-save')).toBeDisabled();
});
