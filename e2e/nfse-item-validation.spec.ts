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


test('fiscal code assistant consults the authenticated JSON catalog without Basic credentials', async ({ page }, testInfo) => {
  const itemId = process.env.NFSE_E2E_ITEM_VALIDATION_ID;
  expect(itemId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  const catalogRequest = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === 'GET'
      && url.pathname === '/1/nfse/national-services'
      && url.searchParams.get('limit') === '500';
  });
  // Core plan limits redirect item creation in CI. The seeded item's edit
  // form renders the same fiscal assistant without bypassing that restriction.
  const navigation = await page.goto('/1/common/items/' + itemId + '/edit', { waitUntil: 'domcontentloaded' });
  expect(navigation?.status()).toBe(200);
  await expect(page).toHaveURL(new RegExp('/common/items/' + itemId + '/edit$'));

  const assistant = page.locator('[data-nfse-tax-code-assistant]');
  await expect(assistant).toBeVisible();

  // The selector is populated from JSON, not an HTML-embedded catalog.
  const loadedCatalog = await catalogRequest;
  expect(loadedCatalog.status()).toBe(200);
  expect(loadedCatalog.headers()['content-type']).toContain('application/json');
  expect(loadedCatalog.request().headers()['authorization']).toBeUndefined();
  const listing = await loadedCatalog.json();
  expect(Array.isArray(listing.data)).toBe(true);
  expect(listing.data.length).toBeGreaterThan(100);
  const field = page.locator('[name="nfse_codigo_tributacao_nacional"]');
  await expect.poll(() => field.evaluate((element) => {
    const component = (window as any).NfseTaxCodeAssistant.selectComponent(element);
    return Array.isArray(component?.sorted_options)
      ? component.sorted_options.some((option: { key: string }) => option.key === '010101')
      : false;
  })).toBe(true);

  const requestPromise = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return request.method() === 'GET'
      && url.pathname === '/1/nfse/national-services'
      && url.searchParams.get('q') === 'consultoria';
  });
  await assistant.locator('[data-nfse-tax-code-query]').fill('consultoria');
  const request = await requestPromise;

  expect(request.headers()['authorization']).toBeUndefined();
  const response = await request.response();
  expect(response?.status()).toBe(200);
  expect(response?.headers()['content-type']).toContain('application/json');
  const body = await response?.json();
  expect(Array.isArray(body?.data)).toBe(true);
});
