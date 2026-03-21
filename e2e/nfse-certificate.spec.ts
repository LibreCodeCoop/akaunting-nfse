// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import path from 'path';
import { test, expect, type Page } from '@playwright/test';
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

test.use({ serviceWorkers: 'block' });

async function mockCertificateParse(page: Page, status: number, body: Record<string, unknown>) {
    await page.addInitScript(({ mockedStatus, mockedBody }) => {
        const originalFetch = window.fetch.bind(window);

        window.fetch = async (input, init) => {
            const url = typeof input === 'string'
                ? input
                : input instanceof URL
                    ? input.toString()
                    : input.url;

            if (url.includes('/certificate/parse')) {
                return new Response(JSON.stringify(mockedBody), {
                    status: mockedStatus,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return originalFetch(input, init);
        };
    }, { mockedStatus: status, mockedBody: body });
}

test.describe('NFS-e certificate upload', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        await loginToAkaunting(page, testInfo);
    });

    test('certificate step uses a single primary action and hides step 2 initially', async ({ page }) => {
        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');

        const fileInput = page.locator('input[name="pfx_file"]');
        const passwordInput = page.locator('input[name="pfx_password"]');
        const readButton = page.locator('#btn-read-cert');
        const stepTwo = page.locator('#step-settings-section');

        await expect(fileInput).toBeAttached();
        await expect(passwordInput).toBeVisible();
        await expect(passwordInput).toHaveAttribute('type', 'password');
        await expect(readButton).toBeVisible();
        await expect(stepTwo).toBeHidden();
    });

    test('file picker accepts only .pfx and .p12 extensions', async ({ page }) => {
        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');

        const fileInput = page.locator('input[name="pfx_file"]');
        await expect(fileInput).toHaveAttribute('accept', /\.pfx|\.p12/);
    });

    test('read-certificate populates CNPJ field from mocked parse response and reveals step 2', async ({ page }) => {
        await mockCertificateParse(page, 200, { data: { cnpj: TEST_CNPJ } });

        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill(TEST_PASSWORD);
        await page.locator('#btn-read-cert').click();

        // CNPJ badge should appear
        await expect(page.locator('#cert-cnpj-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-value')).toHaveText(TEST_CNPJ);
        await expect(page.locator('#step-settings-section')).toBeVisible();

        // CNPJ read-only input in settings form should be updated
        await expect(page.locator('input[name="nfse[cnpj_prestador]"]')).toHaveValue(TEST_CNPJ);
    });

    test('read-certificate shows error for invalid PFX via mocked parse', async ({ page }) => {
        await mockCertificateParse(page, 422, { error: 'Invalid PFX file or incorrect password.' });

        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill('wrong-password');
        await page.locator('#btn-read-cert').click();

        await expect(page.locator('#cert-error-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-display')).not.toBeVisible();
        await expect(page.locator('#step-settings-section')).toBeHidden();
    });
});

