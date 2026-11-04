// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('bulk progress uses scoped session JSON and never embeds fiscal errors in HTML', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  let reads = 0;
  await page.route('**/nfse/bulk/runs?page=*', async route => {
    reads++;
    expect(route.request().headers().accept).toContain('application/json');
    await route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({
        data: [{
          id: 42, status: 'partial', units: [
            {
              id: 91, invoice_id: 150, emission_group_key: 'service:0107',
              status: 'blocked', receipt_id: null, error_type: 'readiness',
              error_message: '<img src=x onerror=alert(1)>',
            },
            {
              id: 92, invoice_id: 151, emission_group_key: 'service:0108',
              status: 'queued', receipt_id: null, error_type: null, error_message: null,
            },
          ],
        }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      }),
    });
  });
  await page.goto('/1/nfse/bulk', { waitUntil: 'domcontentloaded' });
  const root = page.locator('#nfse-bulk-progress');
  const blocked = root.locator('tr[data-bulk-unit-status="blocked"]');
  await expect(blocked).toContainText('service:0107');
  await expect(blocked).toContainText('<img src=x onerror=alert(1)>');
  await expect(blocked.locator('a')).toHaveAttribute('href', /\/sales\/invoices\/150$/);
  await expect(root.locator('tr[data-bulk-unit-status="queued"]')).toHaveCount(1);
  await expect(root.locator('img')).toHaveCount(0);
  await root.locator('#nfse-bulk-refresh').click();
  await expect.poll(() => reads).toBe(2);
});

test('denied bulk status does not leak entries or invoke another transport', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await page.route('**/nfse/bulk/runs?page=*', route => route.fulfill({
    status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'Forbidden' }),
  }));
  await page.goto('/1/nfse/bulk', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#nfse-bulk-status')).toContainText('Sem permissão');
  await expect(page.locator('#nfse-bulk-runs tr')).toHaveCount(0);
});
