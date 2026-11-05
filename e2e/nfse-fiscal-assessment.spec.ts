// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('invoice shows evidence by fiscal group without inventing a taxable event date', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';
  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const section = page.locator('#nfse-native-fiscal-panel [data-nfse-fiscal-event-evidence="true"]');
  await expect(section).toBeVisible();
  await expect(section.locator('[data-nfse-fiscal-event-group]').first()).toBeVisible();

  await section.locator('summary').click();
  const form = section.locator('form[data-nfse-fiscal-event-form="true"]');
  await expect(form).toBeVisible();
  await expect(form.locator('input[name="taxable_event_date"]')).toHaveValue('');
  await expect(form.locator('input[name="source_reference"]')).toHaveValue('');
  await expect(form.locator('input[name="acknowledge"]')).not.toBeChecked();
  // HTML boolean attributes may serialize as required="" or required="required".
  await expect(form.locator('select[name="fiscal_group_key"]')).toHaveJSProperty('required', true);

  // The preview is GET-only, scoped to the same invoice, and never a fiscal POST.
  const response = await page.request.get(`/1/nfse/invoices/${invoiceId}/fiscal-assessment`, {
    headers: { Accept: 'application/json' },
  });
  expect(response.status()).toBe(200);

  const body = await response.json();
  expect(body.scope).toBe('rtc_calendar_preview');
  expect(body.final_dps_checked).toBe(false);
  expect(Array.isArray(body.data)).toBe(true);
  expect(body.data.length).toBeGreaterThan(0);
  expect(body.data[0].group_key).toBeTruthy();
  expect(Array.isArray(body.data[0].diagnostics)).toBe(true);
});
