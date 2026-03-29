// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import path from 'path';
import fs from 'fs';
import os from 'os';
import { execFileSync } from 'child_process';
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
const REAL_FLOW_CNPJ = '12345678901234';
const REAL_CERT_FLOW_ENABLED = process.env.NFSE_E2E_REAL_CERT_FLOW === '1';

function createTemporaryPfx(password: string): { pfxPath: string; cleanup: () => void } {
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'nfse-e2e-pfx-'));
    const keyPath = path.join(tempDir, 'cert.key');
    const certPath = path.join(tempDir, 'cert.crt');
    const pfxPath = path.join(tempDir, 'cert.p12');

    execFileSync('openssl', [
        'req',
        '-x509',
        '-newkey',
        'rsa:2048',
        '-keyout',
        keyPath,
        '-out',
        certPath,
        '-days',
        '2',
        '-nodes',
        '-subj',
        `/C=BR/ST=RJ/L=Niteroi/O=NfseE2E/OU=QA/CN=NfseE2E:${REAL_FLOW_CNPJ}/serialNumber=${REAL_FLOW_CNPJ}`,
    ]);

    execFileSync('openssl', [
        'pkcs12',
        '-export',
        '-out',
        pfxPath,
        '-inkey',
        keyPath,
        '-in',
        certPath,
        '-name',
        'nfse-e2e',
        '-passout',
        `pass:${password}`,
    ]);

    return {
        pfxPath,
        cleanup: () => {
            fs.rmSync(tempDir, { recursive: true, force: true });
        },
    };
}

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

async function openCertificateTab(page: Page) {
    const certificateTab = page.locator('#tab-btn-certificate');

    await expect(certificateTab).toBeEnabled();
    await certificateTab.click();
    await expect(page.locator('#tab-panel-certificate')).toBeVisible();
}

test.describe('NFS-e certificate upload', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        await loginToAkaunting(page, testInfo);
    });

    test('certificate step uses a single primary action and preserves saved-state visibility', async ({ page }) => {
        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        await openCertificateTab(page);

        const fileInput = page.locator('input[name="pfx_file"]');
        const passwordInput = page.locator('input[name="pfx_password"]');
        const readButton = page.locator('#btn-read-cert');
        const certificatePanel = page.locator('#tab-panel-certificate');
        const showReplaceButton = page.locator('#btn-show-replace-cert');

        await expect(fileInput).toBeAttached();
        await expect(passwordInput).toBeAttached();
        await expect(passwordInput).toHaveAttribute('type', 'password');

        const hasSavedState = await page.locator('text=Estado atualmente salvo').count();
        if (hasSavedState > 0) {
            await expect(certificatePanel).toBeVisible();
            await expect(showReplaceButton).toBeVisible();
            await expect(readButton).toBeHidden();
        } else {
            await expect(certificatePanel).toBeVisible();
            await expect(readButton).toBeVisible();
        }
    });

    test('file picker accepts only .pfx and .p12 extensions', async ({ page }) => {
        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        await openCertificateTab(page);

        const fileInput = page.locator('input[name="pfx_file"]');
        await expect(fileInput).toHaveAttribute('accept', /\.pfx|\.p12/);
    });

    test('read-certificate populates CNPJ field from mocked parse response and reveals step 2', async ({ page }) => {
        await mockCertificateParse(page, 200, { data: { cnpj: TEST_CNPJ } });

        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        await openCertificateTab(page);

        if (await page.locator('#btn-show-replace-cert').count()) {
            await page.locator('#btn-show-replace-cert').click();
        }

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill(TEST_PASSWORD);
        await page.locator('#btn-read-cert').click();

        // CNPJ badge should appear
        await expect(page.locator('#cert-cnpj-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-value')).toHaveText(TEST_CNPJ);
        await expect(page.locator('#btn-upload-cert')).toBeEnabled();
    });

    test('read-certificate shows error for invalid PFX via mocked parse', async ({ page }) => {
        await mockCertificateParse(page, 422, { error: 'Invalid PFX file or incorrect password.' });

        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        await openCertificateTab(page);

        if (await page.locator('#btn-show-replace-cert').count()) {
            await page.locator('#btn-show-replace-cert').click();
        }

        await page.locator('input[name="pfx_file"]').setInputFiles(FIXTURE);
        await page.locator('input[name="pfx_password"]').fill('wrong-password');
        await page.locator('#btn-read-cert').click();

        await expect(page.locator('#cert-error-display')).toBeVisible();
        await expect(page.locator('#cert-cnpj-display')).not.toBeVisible();
        await expect(page.locator('#tab-panel-certificate')).toBeVisible();
    });

    test('save on certificate tab never navigates to IBGE JSON endpoint', async ({ page }) => {
        await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        await openCertificateTab(page);

        const uploadButton = page.locator('#btn-upload-cert');
        const fileInput = page.locator('input[name="pfx_file"]');
        const passwordInput = page.locator('input[name="pfx_password"]');
        const showReplaceButton = page.locator('#btn-show-replace-cert');

        if (await uploadButton.isDisabled()) {
            if (await showReplaceButton.count()) {
                await showReplaceButton.click();
            }

            await fileInput.setInputFiles(FIXTURE);
            await passwordInput.fill('wrong-password');
            await expect(uploadButton).toBeEnabled();
        }

        await uploadButton.click();
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/\/1\/nfse\/settings(\?tab=certificate)?/);
        await expect(page.locator('body')).not.toContainText('{"data":[');
        await expect(page).not.toHaveURL(/\/1\/nfse\/ibge\/municipalities\//);

        const certificateTab = page.locator('#tab-btn-certificate');
        await expect(certificateTab).toBeEnabled();
        await certificateTab.click();
        await expect(page.locator('#tab-panel-certificate')).toBeVisible();
    });

    test('real replace flow stores certificate password in Vault and shows saved state as present', async ({ page }, testInfo) => {
        if (!REAL_CERT_FLOW_ENABLED) {
            test.skip(true, 'Set NFSE_E2E_REAL_CERT_FLOW=1 to run real Vault certificate flow.');
        }

        const generatedPassword = `pw-${Date.now()}-Vault!`;
        const { pfxPath, cleanup } = createTemporaryPfx(generatedPassword);

        try {
            await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
            await page.waitForLoadState('networkidle');
            await openCertificateTab(page);

            const hasSavedState = await page.locator('text=Estado atualmente salvo').count();
            if (hasSavedState === 0) {
                test.skip(true, 'This test requires an existing saved NFS-e configuration to run replace flow.');
            }

            const showReplaceButton = page.locator('#btn-show-replace-cert');
            if (await showReplaceButton.count()) {
                await showReplaceButton.click();
            }

            await page.locator('input[name="pfx_file"]').setInputFiles(pfxPath);
            await page.locator('input[name="pfx_password"]').fill(generatedPassword);

            await page.locator('#btn-upload-cert').click();
            await page.waitForLoadState('networkidle');

            await expect(page).toHaveURL(/\/1\/nfse\/settings\?tab=certificate/);
            await expect(page.locator('body')).toContainText('Certificado enviado e senha armazenada com segurança.');
            await expect(page.locator('text=Senha no Vault: presente')).toBeVisible();

            const vaultTab = page.locator('#tab-btn-vault');
            await expect(vaultTab).toBeEnabled();
            await vaultTab.click();
            await expect(page.locator('#tab-panel-vault')).toBeVisible();
            await expect(page.locator('#vault-status-certificate-secret')).toContainText('Sim');
        } finally {
            cleanup();
        }
    });
});

