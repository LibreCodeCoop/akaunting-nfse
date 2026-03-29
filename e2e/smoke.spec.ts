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

test('pending invoices page is reachable after login', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  const response = await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/1\/nfse\/invoices\/pending/);
  expect(response?.status()).toBe(200);
});
