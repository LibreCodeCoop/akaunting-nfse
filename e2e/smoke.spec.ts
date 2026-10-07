// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('login page is reachable', async ({ page }) => {
  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/auth\/login$/);
  await expect(page.getByRole('heading', { name: /login to start your session/i })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
});

test('legacy pending invoices route redirects to native Akaunting invoices', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toBeVisible();

  await expect(page).toHaveURL(/\/1\/sales\/invoices(?:\?.*)?$/);
});


test('native fiscal action opens the NFS-e modal', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');
  await expect(emitAction).toBeVisible();
  await emitAction.click();

  await expect(page.locator('[role="dialog"]').last()).toBeVisible();
});


test('post-emission polling unlocks fiscal artifacts after queue completion', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await page.route('**/nfse-test-post-emission-status', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          status: 'completed',
          poll: false,
          stages: {
            artifacts: 'completed',
            email: 'completed',
          },
          artifacts: {
            xml: {
              ready: true,
              download_url: '/1/nfse/invoices/999/artifacts/xml',
            },
            danfse: {
              ready: true,
              download_url: '/1/nfse/invoices/999/artifacts/danfse',
            },
          },
          error: null,
        },
      }),
    });
  });

  const panel = page.locator('#nfse-native-fiscal-panel');
  await expect(panel).toBeVisible();

  await page.evaluate(() => {
    const root = document.querySelector<HTMLElement>('#nfse-native-fiscal-panel');

    if (!root) {
      throw new Error('NFS-e native fiscal panel was not found.');
    }

    root.dataset.nfsePostEmissionStatusUrl = '/nfse-test-post-emission-status';
    root.dataset.nfsePostEmissionState = 'processing';
    root.dataset.messageProcessing = 'Processing';
    root.dataset.messageCompleted = 'Completed';
    root.dataset.messageFailed = 'Failed';
    root.dataset.stageCompleted = 'Completed';

    root.insertAdjacentHTML('beforeend', `
      <div data-nfse-post-emission-box>
        <span data-nfse-post-emission-spinner>spinner</span>
        <span data-nfse-post-emission-message>Processing</span>
        <span data-nfse-email-status>Pending</span>
      </div>
      <a data-nfse-artifact="xml" aria-disabled="true" class="pointer-events-none opacity-60">
        <span data-nfse-artifact-spinner>spinner</span>
        <span data-nfse-artifact-label>XML</span>
      </a>
      <a data-nfse-artifact="danfse" aria-disabled="true" class="pointer-events-none opacity-60">
        <span data-nfse-artifact-spinner>spinner</span>
        <span data-nfse-artifact-label>DANFSE</span>
      </a>
    `);

    const api = (window as typeof window & {
      NfsePostEmissionStatus?: { start: (node: HTMLElement) => void };
    }).NfsePostEmissionStatus;

    if (!api) {
      throw new Error('NFS-e post-emission polling module was not loaded.');
    }

    api.start(root);
  });

  await expect(panel.locator('[data-nfse-post-emission-message]')).toHaveText('Completed');
  await expect(panel.locator('[data-nfse-post-emission-spinner]')).toHaveClass(/hidden/);
  await expect(panel.locator('[data-nfse-artifact="xml"]')).toHaveAttribute('aria-disabled', 'false');
  await expect(panel.locator('[data-nfse-artifact="danfse"]')).toHaveAttribute('aria-disabled', 'false');
});
