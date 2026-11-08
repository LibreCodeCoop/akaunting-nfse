// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('invoice shows a reversible fiscal decision form without issuing anything', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';
  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/sales/invoices/' + invoiceId, { waitUntil: 'domcontentloaded' });
  const issue = page.locator('[data-nfse-choice="issue"]');
  const doNotIssue = page.locator('[data-nfse-choice="do_not_issue"]');
  await expect(issue).toHaveAttribute('open', '');
  const form = page.locator('[data-nfse-decision-form="true"]');
  await expect(form).toBeHidden();
  await doNotIssue.locator('summary').click();
  await expect(doNotIssue).toHaveAttribute('open', '');
  await expect(issue).not.toHaveAttribute('open', '');
  await expect(form).toBeVisible();
  await expect(form.locator('select[name="decision"] option[value="do_not_issue"]')).toHaveCount(1);
  await expect(form.locator('select[name="decision"] option[value="not_applicable"]')).toHaveCount(1);
  await expect(form.locator('textarea[name="justification"]')).toHaveJSProperty('required', true);
  await expect(form.locator('input[name="evidence_reference"]')).toHaveCount(1);

  // GET-only checks; never submit to the real fiscal environment.
  const response = await page.request.get('/1/nfse/invoices/' + invoiceId + '/fiscal-decision', {
    headers: { Accept: 'application/json' },
  });
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(Array.isArray(body.data.history)).toBe(true);
});
