// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('category issuance configuration lives in the native Akaunting form', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings/category-issuance', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/settings\/categories(?:\?.*)?$/);

  await page.goto('/1/settings/categories/create', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form#category')).toBeVisible();
  await expect(page.locator('form[action*="/nfse/settings/category-issuance"]')).toHaveCount(0);
  await expect(page.locator('form#category')).toHaveCount(1);

  // The native category view contains its NFS-e section, not a second form.
  // Type-dependent Vue rendering is separately covered by the feature suite.
  const content = await page.content();
  expect(content).toContain('category');
  await testInfo.attach('native-category-form.png', {
    body: await page.locator('form#category').screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  });
});
