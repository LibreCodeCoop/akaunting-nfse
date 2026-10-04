// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('[live-fiscal] read-only ADN query proves protected fiscal transport', async ({ page }, testInfo) => {
  if (process.env.NFSE_E2E_LIVE_FISCAL !== '1') {
    test.skip(true, 'Live fiscal tests require NFSE_E2E_LIVE_FISCAL=1.');
  }

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/adn', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#adn-distribution-browser')).toBeVisible();
  await expect(page.locator('#adn-distribution-query')).toBeEnabled();

  await page.locator('#adn-distribution-query').click();

  await expect(page.locator('#adn-distribution-status')).not.toContainText(/error|erro|falha|failed/i);
  await expect(page.locator('#adn-distribution-summary')).toBeVisible();
});
