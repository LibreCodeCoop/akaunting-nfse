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
