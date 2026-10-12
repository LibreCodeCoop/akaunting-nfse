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

  const distributionResponse = page.waitForResponse(
    (response) => response.url().includes('/nfse/adn/distribution?') && response.request().method() === 'GET',
    { timeout: 15_000 },
  );

  await page.locator('#adn-distribution-query').click();

  const response = await distributionResponse;
  expect(response.ok()).toBeTruthy();
  await expect(page.locator('#adn-distribution-status')).not.toContainText(/error|erro/i);
  await expect(page.locator('#adn-distribution-summary')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#adn-summary-status')).toHaveText('PROCESSADO');
  await expect(page.locator('#adn-summary-documents')).toHaveText('0');
  await expect(page.locator('#adn-summary-last-nsu')).toHaveText('0');
});

test('deterministic fiscal settings report the synthetic provider configuration', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings?tab=fiscal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(
    page.getByRole('textbox', { name: 'CNPJ (extracted from certificate)' }),
  ).toHaveValue('11222333000181');
  await expect(
    page.getByRole('textbox', { name: 'Municipality IBGE Code' }),
  ).toHaveValue('3303302');
});

test('deterministic settings preserve selected regime on invalid tax transitions without posting', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/settings?tab=fiscal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const simple = page.locator('#opcao_simples_nacional');
  const issqn = page.locator('#tributacao_issqn');
  const regime = page.locator('#regime_especial_tributacao');

  await expect(regime).toBeVisible();
  await simple.selectOption('1');
  await issqn.selectOption('1');
  await regime.selectOption('6');
  await expect(regime).toHaveValue('6');

  await simple.selectOption('2');
  await expect(regime).toHaveValue('6');
  await expect(regime).toHaveAttribute('aria-invalid', 'true');
  await expect(regime.locator('option[value="9"]')).toBeDisabled();
  await regime.selectOption('0');
  await expect(regime).toHaveAttribute('aria-invalid', 'false');

  await issqn.selectOption('3');
  await expect(regime.locator('option[value="6"]')).toBeDisabled();
  await expect(regime).toHaveValue('0');
});
