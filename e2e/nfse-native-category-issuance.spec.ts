// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('category issuance configuration lives in the native Akaunting form', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings/category-issuance', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/settings\/categories(?:\?.*)?$/);

  // In the disposable fixture the hosted Akaunting plan API is unavailable.
  // The native plan-limit middleware exempts AJAX GETs from redirects to
  // /auth/users. This still renders and tests the real category create view.
  await page.setExtraHTTPHeaders({ 'X-Requested-With': 'XMLHttpRequest' });
  await page.goto('/1/settings/categories/create', { waitUntil: 'domcontentloaded' });
  await page.setExtraHTTPHeaders({});
  await expect(page.locator('form#category')).toBeVisible();
  await expect(page.locator('form[action*="/nfse/settings/category-issuance"]')).toHaveCount(0);
  await expect(page.locator('form#category')).toHaveCount(1);

  // Select Income using the native category type selector.
  await page.locator('#form-select-type .el-input').click();
  await page.locator('.el-select-dropdown:visible .el-select-dropdown__item')
    .filter({ hasText: /^(Income|Receita)$/i }).first().click();
  await expect(page.locator('form#category [data-nfse-native-category-policy="true"]'))
    .toBeVisible();
  await testInfo.attach('native-category-form.png', {
    body: await page.locator('form#category').screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  });
});
