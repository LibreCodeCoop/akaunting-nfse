// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('native invoice detail shows every linked fiscal-group receipt', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_GROUPED_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const panel = page.locator('#nfse-native-fiscal-panel');
  await expect(panel).toBeVisible();

  const receipts = panel.locator('[data-nfse-receipt-id]');
  await expect(receipts).toHaveCount(2);

  await expect(panel).toContainText('5101');
  await expect(panel).toContainText('5102');
  await expect(panel).toContainText('service:0107|tax:010701|rate:2.00');
  await expect(panel).toContainText('service:0101|tax:010101|rate:3.00');
  await expect(panel).toContainText('55555555555555555555555555555555555555555555555555');
  await expect(panel).toContainText('66666666666666666666666666666666666666666666666666');
});
