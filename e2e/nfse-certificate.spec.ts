// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import path from 'path';
import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

/**
 * Certificate upload E2E tests.
 *
 * The backend certificate endpoint stores the PKCS#12 in OpenBao/Vault.
 * In CI that infrastructure is not available, so the POST is intercepted
 * and a synthetic JSON response is returned. This lets us verify all the
 * UI behaviour (file picker, password field, form submission) without any
 * real company credential.
 *
 * The fixture `e2e/fixtures/test-cert.p12` is a self-signed certificate
 * generated only for testing. It has no legal value and no connection to
 * any ICP-Brasil authority.
 */

const FIXTURE = path.join(__dirname, 'fixtures', 'test-cert.p12');
const TEST_PASSWORD = 'test-password-only';
const TEST_CNPJ = '12345678000195';

test.describe('NFS-e certificate upload', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        await loginToAkaunting(page, testInfo);
        await page.goto('/nfse/settings', { waitUntil: 'domcontentloaded' });
    });

    test('certificate upload form is present with correct fields', async ({ page }) => {
        const fileInput = page.locator('input[name="pfx_file"]');
        const passwordInput = page.locator('input[name="pfx_password"]');
        const uploadButton = page.locator('form[action*="nfse/certificate"] button[type="submit"]');

        await expect(fileInput).toBeAttached();
        await expect(passwordInput).toBeVisible();
        await expect(passwordInput).toHaveAttribute('type', 'password');
        await expect(uploadButton).toBeVisible();
        await expect(uploadButton).not.toHaveText(/general\.upload/i);
    });

    test('read-certificate button is rendered before upload button', async ({ page }) => {
        const readBtn = page.locator('#btn-read-cert');
        const uploadBtn = page.locator('#cert-form button[type="submit"]');

        await expect(readBtn).toBeVisible();
        await expect(uploadBtn).toBeVisible();

        const readBtnBox = await readBtn.boundingBox();
        const uploadBtnBox = await uploadBtn.boundingBox();

        // "Ler certificado" must appear above or to the left of "Enviar"
        expect(readBtnBox).not.toBeNull();
        expect(uploadBtnBox).not.toBeNull();
        expect((readBtnBox!.x + readBtnBox!.width) <= uploadBtnBox!.x || readBtnBox!.y <= uploadBtnBox!.y).toBeTruthy();
    });

    test('file picker accepts only .pfx and .p12 extensions', async ({ page }) => {
        const fileInput = page.locator('input[name="pfx_file"]');
        await expect(fileInput).toHaveAttribute('accept', /\.pfx|\.p12/);
    });

    test('read-certificate populates CNPJ field from mocked parse response', async ({ page }) => {
        await page.route('**/nfse/certificate/parse', (route) => {
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: { cnpj: TEST_CNPJ } }),
            });
        });

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill(TEST_PASSWORD);
        await page.locator('#btn-read-cert').click();

        // CNPJ badge should appear
        await expect(page.locator('#cert-cnpj-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-value')).toHaveText(TEST_CNPJ);

        // CNPJ read-only input in settings form should be updated
        await expect(page.locator('input[name="nfse[cnpj_prestador]"]')).toHaveValue(TEST_CNPJ);
    });

    test('read-certificate shows error for invalid PFX via mocked parse', async ({ page }) => {
        await page.route('**/nfse/certificate/parse', (route) => {
            route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'Invalid PFX file or incorrect password.' }),
            });
        });

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill('wrong-password');
        await page.locator('#btn-read-cert').click();

        await expect(page.locator('#cert-error-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-display')).not.toBeVisible();
    });

    test('uploads test certificate and shows success with mocked backend', async ({ page }) => {
        // Intercept the upload POST — avoids needing OpenBao/Vault in CI.
        await page.route('**/nfse/certificate', (route) => {
            if (route.request().method() === 'POST') {
                route.fulfill({
                    status: 302,
                    headers: { Location: '/nfse/settings' },
                });
            } else {
                route.continue();
            }
        });

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill(TEST_PASSWORD);

        await Promise.all([
            page.waitForResponse((res) => res.url().includes('/nfse/certificate') && res.request().method() === 'POST'),
            page.locator('form#cert-form button[type="submit"]').click(),
        ]);

        // After the redirect the user lands back on /nfse/settings.
        await expect(page).toHaveURL(/\/nfse\/settings/);
    });
});

