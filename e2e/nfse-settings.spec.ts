// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('NFS-e settings screen is reachable and visible', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

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

  await page.goto('/nfse/settings', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/nfse\/settings/);
  await expect(page.getByRole('heading', { name: /NFS-e/i })).toBeVisible();
  await expect(page.locator('input[name="nfse[cnpj_prestador]"]')).toBeVisible();
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
