// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('emitted native invoice exposes explicit substitution review form', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_SUBSTITUTION_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const panel = page.locator('#nfse-native-fiscal-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('4242');
  await expect(panel).toContainText('44444444444444444444444444444444444444444444444444');

  const form = panel.locator(`form[action$="/nfse/invoices/${invoiceId}/substitute"]`);
  const details = form.locator('xpath=ancestor::details[1]');
  await details.locator('summary').click();

  await expect(form).toBeVisible();

  const reason = form.locator('select[name="nfse_substitution_reason"]');
  await expect(reason).toBeVisible();
  await expect(reason.locator('option')).toHaveCount(6);
  await reason.selectOption('99');

  const description = form.locator('textarea[name="nfse_substitution_description"]');
  await expect(description).toHaveAttribute('minlength', '15');
  await expect(description).toHaveAttribute('maxlength', '255');
  await description.fill('Correcao fiscal revisada pelo operador no fluxo de substituicao.');

  await expect(form.locator('input[name="nfse_substitution_receipt_id"]')).toHaveValue(/\d+/);
  await expect(form.getByRole('button', { name: /substit|replacement/i })).toBeVisible();

  await expect(panel).not.toContainText(/reemit|reemitir/i);
});


test('native fiscal receipt copies its own access key and exposes disclosure state', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_SUBSTITUTION_INVOICE_ID ?? '';
  expect(invoiceId).toMatch(/^\d+$/);

  await page.addInitScript(() => {
    const testWindow = window as typeof window & { __nfseCopiedKeys: string[] };
    testWindow.__nfseCopiedKeys = [];
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        async writeText(value: string) {
          testWindow.__nfseCopiedKeys.push(value);
        },
      },
    });
  });

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/sales/invoices/' + invoiceId, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const panel = page.locator('#nfse-native-fiscal-panel');
  const receipt = panel.locator('[data-nfse-receipt-id]').first();
  await expect(receipt.locator('[data-nfse-access-key]')).toHaveText('4'.repeat(50));

  await receipt.getByRole('button', { name: /copy|copiar/i }).click();
  await expect(receipt.locator('[data-nfse-copy-feedback]')).toContainText(/copied|copiad/i);

  const copied = await page.evaluate(() =>
    (window as typeof window & { __nfseCopiedKeys: string[] }).__nfseCopiedKeys,
  );
  expect(copied).toEqual(['4'.repeat(50)]);

  const disclosure = panel.locator('[data-nfse-native-substitute="true"]');
  const summary = disclosure.locator('summary');
  await summary.click();
  await expect(disclosure).toHaveAttribute('open', '');
  await expect(disclosure.locator('[data-nfse-disclosure-chevron]')).toHaveAttribute('style', /rotate\(180deg\)/);

  await summary.click();
  await expect(disclosure).not.toHaveAttribute('open', '');
  await expect(disclosure.locator('[data-nfse-disclosure-chevron]')).toHaveAttribute('style', /rotate\(0deg\)/);
});
