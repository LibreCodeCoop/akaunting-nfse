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
