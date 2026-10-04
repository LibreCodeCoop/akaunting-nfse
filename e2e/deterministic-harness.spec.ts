// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('deterministic ADN browser reaches fiscal transport without real A1 or government network', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/adn', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#adn-distribution-browser')).toBeVisible();
  await expect(page.locator('#adn-cnpj')).toHaveValue('11222333000181');
  await expect(page.locator('#adn-nsu')).toHaveValue('0');

  await page.locator('#adn-distribution-query').click();

  await expect(page.locator('#adn-distribution-status')).not.toContainText(/error|erro/i);
  await expect(page.locator('#adn-distribution-summary')).toBeVisible();
  await expect(page.locator('#adn-summary-status')).toHaveText('PROCESSADO');
  await expect(page.locator('#adn-summary-documents')).toHaveText('0');
  await expect(page.locator('#adn-summary-last-nsu')).toHaveText('0');
});

test('deterministic fiscal settings report the synthetic provider configuration', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings?tab=fiscal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('input[name="cnpj_prestador"]')).toHaveValue('11222333000181');
  await expect(page.locator('input[name="municipio_ibge"]')).toHaveValue('3303302');
});
