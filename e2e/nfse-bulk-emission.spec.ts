// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('selected native invoices enter controlled bulk progress with individual outcomes', async ({ page }, testInfo) => {
  const readyId = process.env.NFSE_E2E_BULK_READY_INVOICE_ID ?? '';
  const blockedId = process.env.NFSE_E2E_BULK_BLOCKED_INVOICE_ID ?? '';

  expect(readyId).toMatch(/^\d+$/);
  expect(blockedId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/sales/invoices', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const ready = page.locator(`[data-bulk-action="${readyId}"]`);
  const blocked = page.locator(`[data-bulk-action="${blockedId}"]`);

  await expect(ready).toBeVisible();
  await expect(blocked).toBeVisible();
  await ready.check();
  await blocked.check();

  const form = page.locator('[data-nfse-bulk-dispatch-form]');
  const submit = form.getByRole('button');

  await expect(form).toBeVisible();
  await expect(submit).toBeEnabled();

  await Promise.all([
    page.waitForURL(/\/1\/nfse\/bulk$/),
    submit.click(),
  ]);

  const progress = page.locator('#nfse-bulk-progress');
  await expect(progress).toBeVisible();

  const readyRow = progress.locator('tr').filter({
    has: page.locator(`a[href$="/sales/invoices/${readyId}"]`),
  });
  const blockedRow = progress.locator('tr').filter({
    has: page.locator(`a[href$="/sales/invoices/${blockedId}"]`),
  });

  await expect(readyRow).toBeVisible();
  await expect(blockedRow).toBeVisible();
  await expect(readyRow).toHaveAttribute('data-bulk-unit-status', /queued|processing|issued/);
  await expect(blockedRow).toHaveAttribute('data-bulk-unit-status', 'blocked');
  await expect(blockedRow).toContainText('foreign_taker_requires_review');
});
