// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('item list exposes the fiscal validation badge and edit action', async ({ page }, testInfo) => {
  const itemId = process.env.NFSE_E2E_ITEM_VALIDATION_ID;

  expect(itemId).toBeTruthy();

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/common/items', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const editLink = page.locator('#index-line-actions-edit-item-' + itemId);
  await expect(editLink).toBeAttached();

  const row = editLink.locator('xpath=ancestor::tr');
  const badge = row.locator('[data-nfse-fiscal-validation]');

  await expect(badge).toBeVisible();
  await expect(badge).toHaveAttribute('data-nfse-fiscal-validation', 'valid');
  await expect(badge).toHaveAccessibleName(/NFS-e:/);
  await expect(badge).toHaveAttribute(
    'href',
    new RegExp('/common/items/' + itemId + '/edit#nfse-fiscal-fields$'),
  );
});
