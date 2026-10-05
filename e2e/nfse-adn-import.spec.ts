// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('received ADN NFS-e can be reviewed and imported as a draft bill', async ({ page }, testInfo) => {
  const documentId = process.env.NFSE_E2E_ADN_REVIEW_DOCUMENT_ID;
  const vendorId = process.env.NFSE_E2E_ADN_VENDOR_ID;
  const categoryId = process.env.NFSE_E2E_ADN_CATEGORY_ID;
  const itemId = process.env.NFSE_E2E_ADN_ITEM_ID;

  test.skip(
    !documentId || !vendorId || !categoryId || !itemId,
    'Provision the deterministic ADN review fixture before running this test.',
  );

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/adn', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const form = page.locator(`form[action*="/adn/review/${documentId}/import"]`);
  await expect(form).toBeVisible();
  await expect(form).toContainText('Fornecedor ADN E2E Ltda');
  await expect(form).toContainText('100.00');

  await form.locator('select[name="contact_id"]').selectOption(vendorId!);
  await form.locator('select[name="category_id"]').selectOption(categoryId!);
  await form.locator('select[name="item_id"]').selectOption(itemId!);
  await form.locator('input[name="issued_at"]').fill('2026-10-01');
  await form.locator('input[name="due_at"]').fill('2026-10-31');

  await Promise.all([
    page.waitForURL(/\/purchases\/bills\/\d+/),
    form.locator('button[type="submit"]').click(),
  ]);

  await expect(page).toHaveURL(/\/purchases\/bills\/\d+/);
  await expect(page.locator('body')).toContainText('NFSE E2E Vendor');
  await expect(page.locator('body')).toContainText('100.00');
});
