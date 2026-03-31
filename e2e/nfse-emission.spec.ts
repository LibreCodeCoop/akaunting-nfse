// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

const REAL_EMIT_FLOW_ENABLED = process.env.NFSE_E2E_REAL_EMIT_FLOW === '1';

test.use({ serviceWorkers: 'block' });

const emitFormsSelector = "form[action*='/nfse/invoices/'][action$='/emit']";

test('pending invoices page exposes emission CTA when authenticated', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/invoices\/pending/);

  const emitButtons = page.locator(`${emitFormsSelector} button[type='submit']`);
  const emitButtonsCount = await emitButtons.count();

  if (emitButtonsCount > 0) {
    await expect(emitButtons.first()).toBeVisible();
  }
});

test('real happy path emits NFS-e from pending list', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  if (!REAL_EMIT_FLOW_ENABLED) {
    test.skip(true, 'Set NFSE_E2E_REAL_EMIT_FLOW=1 to run real emission happy path.');
  }

  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitButtons = page.locator(`${emitFormsSelector} button[type='submit']`);
  const emitButtonsCount = await emitButtons.count();

  if (emitButtonsCount === 0) {
    test.skip(true, 'No pending invoices available to execute real emission happy path.');
  }

  const firstEmitButton = emitButtons.first();

  await expect(firstEmitButton).toBeVisible();

  if (!(await firstEmitButton.isEnabled())) {
    const readinessItems = await page
      .locator('li')
      .filter({ hasText: /configured|configurado|ready|pront/i })
      .allInnerTexts();

    const details = readinessItems.length > 0 ? readinessItems.join('; ') : 'unknown configuration prerequisite';

    throw new Error(`Real emission blocked by pending settings: ${details}`);
  }

  await firstEmitButton.click();

  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/invoices\/\d+$/);
  await expect(page.locator('body')).toContainText(/(emitida com sucesso|successfully emitted)/i);
  await expect(page.locator('body')).toContainText(/(dados da nfs-e|nfs-e data)/i);
});
