// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('paid internal invoice remains financial-only while service invoice keeps fiscal controls', async ({ page }, testInfo) => {
  const internalId = process.env.NFSE_E2E_EXCLUDED_INVOICE_ID ?? '';
  const pendingId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';
  expect(internalId).toMatch(/^\d+$/);
  expect(pendingId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${internalId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#nfse-native-fiscal-panel')).toHaveCount(0);
  await expect(page.locator('[data-nfse-native-emit="true"]')).toHaveCount(0);

  await page.goto('/1/sales/invoices?list_records=all', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  const internalRow = page.locator(`[data-bulk-action="${internalId}"]`).locator('xpath=ancestor::tr');
  await expect(internalRow).toBeVisible();
  await expect(internalRow.locator('[data-nfse-list-status]')).toHaveCount(0);
  await testInfo.attach('internal-invoice-financial-only.png', {
    body: await internalRow.screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  });

  await page.goto(`/1/sales/invoices/${pendingId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#nfse-native-fiscal-panel')).toBeVisible();
  await expect(page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]')).toBeVisible();
});
