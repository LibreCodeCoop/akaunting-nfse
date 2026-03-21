// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, Page, TestInfo } from '@playwright/test';

export async function loginToAkaunting(page: Page, testInfo: TestInfo): Promise<void> {
  const email = process.env.NFSE_E2E_EMAIL;
  const password = process.env.NFSE_E2E_PASSWORD;

  if (!email || !password) {
    testInfo.skip(true, 'Set NFSE_E2E_EMAIL and NFSE_E2E_PASSWORD to run Playwright E2E tests.');
    return;
  }

  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form[action*="auth/login"] button[type="submit"]').click();

  await page.waitForLoadState('networkidle');
  await expect(page).not.toHaveURL(/\/auth\/login$/);
}
