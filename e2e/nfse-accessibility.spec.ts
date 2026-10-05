// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('settings tabs expose keyboard-operable tab semantics', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const tablist = page.getByRole('tablist', { name: /NFS-e/i });
  await expect(tablist).toBeVisible();

  const vaultTab = page.locator('#tab-btn-vault');
  await expect(vaultTab).toHaveAttribute('role', 'tab');
  await expect(vaultTab).toHaveAttribute('aria-controls', 'tab-panel-vault');
  await expect(vaultTab).toHaveAttribute('aria-selected', 'true');

  const vaultPanel = page.locator('#tab-panel-vault');
  await expect(vaultPanel).toHaveAttribute('role', 'tabpanel');
  await expect(vaultPanel).toHaveAttribute('aria-labelledby', 'tab-btn-vault');
  await expect(vaultPanel).toHaveAttribute('aria-hidden', 'false');

  await vaultTab.focus();
  await page.keyboard.press('ArrowRight');

  const selectedTab = page.locator('.tab-button[aria-selected="true"]');
  await expect(selectedTab).toBeFocused();

  const controlledPanelId = await selectedTab.getAttribute('aria-controls');
  expect(controlledPanelId).not.toBeNull();

  const selectedPanel = page.locator('#' + controlledPanelId);
  await expect(selectedPanel).toBeVisible();
  await expect(selectedPanel).toHaveAttribute('aria-hidden', 'false');

  await page.keyboard.press('End');
  const lastSelected = page.locator('.tab-button[aria-selected="true"]');
  await expect(lastSelected).toBeFocused();

  await page.keyboard.press('Home');
  await expect(vaultTab).toBeFocused();
  await expect(vaultTab).toHaveAttribute('aria-selected', 'true');
});

test('readiness summary has an accessible section name and actionable links', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const summary = page.locator('#nfse-readiness-summary');
  await expect(summary).toBeVisible();
  await expect(summary).toHaveAttribute('aria-labelledby', 'nfse-readiness-title');

  const title = page.locator('#nfse-readiness-title');
  await expect(title).toBeVisible();
  await expect(title).not.toHaveText('');

  const correctiveLinks = summary.getByRole('link');
  const count = await correctiveLinks.count();

  for (let index = 0; index < count; index += 1) {
    await expect(correctiveLinks.nth(index)).toHaveAccessibleName(/.+/);
  }
});


test('native invoice fiscal panel is an accessible named region', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_GROUPED_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const panel = page.locator('#nfse-native-fiscal-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toHaveAttribute('role', 'region');
  await expect(panel).toHaveAttribute('aria-labelledby', 'nfse-native-fiscal-panel-title');
  await expect(page.locator('#nfse-native-fiscal-panel-title')).not.toHaveText('');

  const actions = panel.getByRole('link').or(panel.getByRole('button'));
  const actionCount = await actions.count();

  for (let index = 0; index < actionCount; index += 1) {
    await expect(actions.nth(index)).toHaveAccessibleName(/.+/);
  }
});

test('ADN distribution browser exposes labelled controls and live status', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/adn', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const browser = page.locator('#adn-distribution-browser');
  await expect(browser).toBeVisible();
  await expect(browser).toHaveAttribute('role', 'region');
  await expect(browser).toHaveAttribute('aria-labelledby', 'adn-distribution-title');
  await expect(page.locator('#adn-distribution-title')).not.toHaveText('');

  await expect(page.locator('#adn-nsu')).toHaveAccessibleName(/.+/);
  await expect(page.locator('#adn-cnpj')).toHaveAccessibleName(/.+/);
  await expect(page.locator('#adn-lote')).toHaveAccessibleName(/.+/);
  await expect(page.locator('#adn-distribution-query')).toHaveAccessibleName(/.+/);
  await expect(page.locator('#adn-distribution-status')).toHaveAttribute('aria-live', 'polite');
});


test('emission modal tabs are keyboard operable with synchronized ARIA state', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');
  await expect(emitAction).toBeVisible();
  await emitAction.click();

  const dialog = page.locator('[role="dialog"]').last();
  await expect(dialog).toBeVisible();

  const tablist = dialog.getByRole('tablist', { name: 'NFS-e' });
  await expect(tablist).toBeVisible();

  const issuance = dialog.locator('#nfse-tab-nav-issuance');
  const email = dialog.locator('#nfse-tab-nav-email');

  await expect(issuance).toHaveAttribute('aria-selected', 'true');
  await expect(email).toHaveAttribute('aria-selected', 'false');

  await issuance.focus();
  await page.keyboard.press('ArrowRight');

  await expect(email).toBeFocused();
  await expect(email).toHaveAttribute('aria-selected', 'true');
  await expect(issuance).toHaveAttribute('aria-selected', 'false');
  await expect(dialog.locator('#nfse-tab-pane-email')).toHaveAttribute('aria-hidden', 'false');
  await expect(dialog.locator('#nfse-tab-pane-issuance')).toHaveAttribute('aria-hidden', 'true');

  await page.keyboard.press('Home');
  await expect(issuance).toBeFocused();
  await expect(issuance).toHaveAttribute('aria-selected', 'true');
});


test('emission error summary receives keyboard focus', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');
  await expect(emitAction).toBeVisible();
  await emitAction.click();

  const dialog = page.locator('[role="dialog"]').last();
  await expect(dialog).toBeVisible();

  await page.route('**/nfse/invoices/*/emit', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }

    await route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({
        error: true,
        message: 'Deterministic fiscal validation error',
      }),
    });
  });

  const form = dialog.locator("form[action*='/nfse/invoices/'][action$='/emit']");
  await expect(form).toBeVisible();

  const submit = dialog.getByRole('button', { name: /Emitir NFS-e agora|Emit NFS-e now/i });
  await expect(submit).toBeVisible();
  await submit.click();

  const summary = dialog.locator('[data-nfse-error-summary="true"]');
  await expect(summary).toBeVisible();
  await expect(summary).toContainText('Deterministic fiscal validation error');
  await expect(summary).toBeFocused();
});
