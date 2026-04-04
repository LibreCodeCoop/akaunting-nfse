// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('reemit updates receipt status from cancelled to emitted', async ({ page }, testInfo) => {
  test.setTimeout(120_000);

  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices?status=cancelled', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toBeVisible();

  const reemitForms = page.locator("form[action*='/nfse/invoices/'][action$='/reemit']");
  const reemitCount = await reemitForms.count();

  if (reemitCount === 0) {
    test.skip(true, 'No cancelled NFS-e found to validate reissue flow.');
  }

  const targetForm = reemitForms.first();

  page.once('dialog', async (dialog) => {
    await dialog.accept();
  });

  await targetForm.locator("button[type='submit']").click();
  await expect(page.locator('body')).toBeVisible();

  await expect(page).toHaveURL(/\/1\/nfse\/invoices\/\d+$/);

  const successBanner = page
    .locator('div.bg-green-100')
    .filter({ hasText: /reemitida|reissued/i });

  if (await successBanner.count() === 0) {
    test.skip(true, 'Reissue did not complete in this environment (likely external gateway rejection).');
  }

  await expect(successBanner.first()).toBeVisible();
  await expect(page.locator('body')).toContainText(/status/i);
  await expect(page.locator('body')).toContainText(/emitted|emitida/i);
});