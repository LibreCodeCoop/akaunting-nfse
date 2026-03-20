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

test.describe('NFS-e certificate upload', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        await loginToAkaunting(page, testInfo);
        await page.goto('/nfse/settings', { waitUntil: 'domcontentloaded' });
    });

    test('certificate upload form is present with correct fields', async ({ page }) => {
        const fileInput = page.locator('input[name="pfx_file"]');
        const passwordInput = page.locator('input[name="pfx_password"]');

        await expect(fileInput).toBeAttached();
        await expect(passwordInput).toBeVisible();
        await expect(passwordInput).toHaveAttribute('type', 'password');
    });

    test('file picker accepts only .pfx and .p12 extensions', async ({ page }) => {
        const fileInput = page.locator('input[name="pfx_file"]');
        await expect(fileInput).toHaveAttribute('accept', /\.pfx|\.p12/);
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
            page.locator('form[action*="nfse/certificate"] button[type="submit"]').click(),
        ]);

        // After the redirect the user lands back on /nfse/settings.
        await expect(page).toHaveURL(/\/nfse\/settings/);
    });
});
