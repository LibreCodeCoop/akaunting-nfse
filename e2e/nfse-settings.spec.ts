// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('replace action works even while async lookups are still loading', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.route('**/nfse/ibge/ufs', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { uf: 'SP', name: 'Sao Paulo' },
          { uf: 'RJ', name: 'Rio de Janeiro' },
        ],
      }),
    });
  });

  await page.route('**/nfse/lc116/services', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          {
            code: '0107',
            display_code: '1.07',
            description: 'Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao',
            label: '1.07 - Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao',
          },
        ],
      }),
    });
  });

  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });

  const showReplaceButton = page.locator('#btn-show-replace-cert');
  if (await showReplaceButton.count()) {
    await showReplaceButton.click();
    await expect(page.locator('#replace-cert-fields')).toBeVisible();
  }
});

test('NFS-e settings screen is reachable and visible', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.route('**/certificate/parse', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { cnpj: '12345678000195' } }),
    });
  });

  await page.route('**/nfse/ibge/ufs', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { uf: 'SP', name: 'Sao Paulo' },
          { uf: 'RJ', name: 'Rio de Janeiro' },
        ],
      }),
    });
  });

  await page.route('**/nfse/ibge/municipalities/SP', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { ibge_code: '3550308', name: 'Sao Paulo' },
          { ibge_code: '3509502', name: 'Campinas' },
        ],
      }),
    });
  });

  await page.route('**/nfse/lc116/services', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          {
            code: '0107',
            display_code: '1.07',
            description: 'Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao',
            label: '1.07 - Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao',
          },
        ],
      }),
    });
  });

  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/settings/);
  await expect(page.locator('h1:has-text("NFS-e")')).toBeVisible();

  // Certificate wizard section appears first
  await expect(page.locator('input[name="pfx_file"]')).toBeAttached();

  const stepSettingsSection = page.locator('#step-settings-section');
  const hasSavedState = await page.locator('text=Estado atualmente salvo').count();
  if (hasSavedState > 0) {
    await expect(stepSettingsSection).toBeVisible();
    await expect(page.locator('#btn-show-replace-cert')).toBeVisible();
    await expect(page.locator('#btn-read-cert')).toBeHidden();
    await page.locator('#btn-show-replace-cert').click();
    await expect(page.locator('#btn-read-cert')).toBeVisible();
  } else {
    await expect(stepSettingsSection).toBeHidden();
    await expect(page.locator('#btn-read-cert')).toBeVisible();
  }

  // CNPJ field is read-only in the settings section
  const cnpjInput = page.locator('input[name="nfse[cnpj_prestador]"]');
  await expect(cnpjInput).toBeAttached();
  await expect(cnpjInput).toHaveAttribute('readonly');

  await page.locator('input[name="pfx_file"]').setInputFiles('e2e/fixtures/test-cert.p12');
  await page.locator('input[name="pfx_password"]').fill('test-password-only');
  await page.locator('#btn-read-cert').click();

  await expect(page.locator('#cert-cnpj-display')).toBeVisible();
  await expect(cnpjInput).toHaveValue('12345678000195');
  await expect(stepSettingsSection).toBeVisible();

  await expect(page.locator('select[name="nfse[uf]"]')).toBeVisible();
  await expect(page.locator('select[name="nfse[municipio_nome]"]')).toBeVisible();
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toBeAttached();
  await expect(page.locator('input[name="nfse[item_lista_servico_display]"]')).toBeVisible();
  await expect(page.locator('input[name="nfse[item_lista_servico]"]')).toBeAttached();

  await page.locator('select[name="nfse[uf]"]').selectOption('SP');
  await page.locator('select[name="nfse[municipio_nome]"]').selectOption('Sao Paulo');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('3550308');

  await page.locator('input[name="nfse[item_lista_servico_display]"]').fill('1.07 - Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao');
  await expect(page.locator('input[name="nfse[item_lista_servico]"]')).toHaveValue('0107');
});

