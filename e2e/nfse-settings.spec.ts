// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, type Page } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

async function openTab(page: Page, tabId: string, panelId: string) {
  const tab = page.locator(tabId);
  await expect(tab).toBeVisible();
  await expect(tab).toBeEnabled();
  await tab.click();
  await expect(page.locator(panelId)).toBeVisible();
}

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

  await openTab(page, '#tab-btn-certificate', '#tab-panel-certificate');

  const showReplaceButton = page.locator('#btn-show-replace-cert');
  if (await showReplaceButton.isVisible()) {
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

  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/settings/);
  await expect(page.locator('h1:has-text("NFS-e")')).toBeVisible();

  await openTab(page, '#tab-btn-certificate', '#tab-panel-certificate');

  await expect(page.locator('input[name="pfx_file"]')).toBeAttached();
  await expect(page.locator('#btn-show-replace-cert')).toBeVisible();
  await expect(page.locator('#btn-read-cert')).toBeHidden();
  await page.locator('#btn-show-replace-cert').click();
  await expect(page.locator('#replace-cert-fields')).toBeVisible();
  await expect(page.locator('#btn-read-cert')).toBeVisible();

  await page.locator('input[name="pfx_file"]').setInputFiles('e2e/fixtures/test-cert.p12');
  await page.locator('input[name="pfx_password"]').fill('test-password-only');
  await page.locator('#btn-read-cert').click();

  await expect(page.locator('#cert-cnpj-display')).toBeVisible();
  await expect(page.locator('#cert-cnpj-value')).toHaveText('12345678000195');
  await expect(page.locator('#btn-upload-cert')).toBeEnabled();

  await openTab(page, '#tab-btn-fiscal', '#tab-panel-fiscal');

  const cnpjInput = page.locator('input[name="nfse[cnpj_prestador]"]');
  await expect(cnpjInput).toBeVisible();
  await expect(cnpjInput).toHaveAttribute('readonly');

  await expect(page.locator('select[name="nfse[uf]"]')).toBeVisible();
  await expect(page.locator('select[name="nfse[municipio_nome]"]')).toBeVisible();
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toBeAttached();

  await page.locator('select[name="nfse[uf]"]').selectOption('SP');
  await page.locator('select[name="nfse[municipio_nome]"]').selectOption('Sao Paulo');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('3550308');
});

test('vault status summary shows certificate secret checklist row', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/settings/);
  await expect(page.locator('#vault-status-certificate-secret')).toBeVisible();
  await expect(page.locator('text=Segredo do certificado no Vault')).toBeVisible();
});

test('federal tab visibility matrix matches situacao tributaria and tipo retencao', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/settings?tab=federal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#tab-panel-federal')).toBeVisible();

  const situacaoSelect = page.locator('#federal-piscofins-situacao');
  const tipoRetencaoSelect = page.locator('#federal-piscofins-tipo-retencao');
  const piscofinsPanel = page.locator('#federal-piscofins-panel');
  const csllRow = page.locator('#federal-valor-csll-row');

  // Situacao 0/empty: panel and CSLL row must be hidden regardless of retention type.
  for (const situacao of ['', '0']) {
    await situacaoSelect.selectOption(situacao);
    await expect(piscofinsPanel).toBeHidden();
    await expect(csllRow).toBeHidden();
  }

  // Situacao with tributacao enabled: panel visible, CSLL controlled by retention type.
  await situacaoSelect.selectOption('1');
  await expect(piscofinsPanel).toBeVisible();

  const matrix = [
    { tipoRetencao: '0', shouldShowCsll: false },
    { tipoRetencao: '3', shouldShowCsll: true },
    { tipoRetencao: '4', shouldShowCsll: false },
    { tipoRetencao: '5', shouldShowCsll: false },
    { tipoRetencao: '6', shouldShowCsll: false },
    { tipoRetencao: '7', shouldShowCsll: true },
    { tipoRetencao: '8', shouldShowCsll: true },
    { tipoRetencao: '9', shouldShowCsll: true },
  ];

  for (const entry of matrix) {
    await tipoRetencaoSelect.selectOption(entry.tipoRetencao);

    if (entry.shouldShowCsll) {
      await expect(csllRow).toBeVisible();
    } else {
      await expect(csllRow).toBeHidden();
    }
  }
});

test('fiscal tab resets municipality IBGE when UF changes and updates after new selection', async ({ page }, testInfo) => {
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

  await page.route('**/nfse/ibge/municipalities/RJ', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { ibge_code: '3303302', name: 'Niteroi' },
          { ibge_code: '3304557', name: 'Rio de Janeiro' },
        ],
      }),
    });
  });

  await page.goto('/1/nfse/settings?tab=fiscal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#tab-panel-fiscal')).toBeVisible();

  await page.locator('select[name="nfse[uf]"]').selectOption('SP');
  await page.locator('select[name="nfse[municipio_nome]"]').selectOption('Sao Paulo');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('3550308');

  await page.locator('select[name="nfse[uf]"]').selectOption('RJ');

  // Changing UF must clear stale municipality/IBGE until the user selects a city from the new UF.
  await expect(page.locator('select[name="nfse[municipio_nome]"]')).toHaveValue('');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('');

  await page.locator('select[name="nfse[municipio_nome]"]').selectOption('Niteroi');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('3303302');
});

test('full dependent setup flow covers vault, certificate, fiscal and services steps', async ({ page }, testInfo) => {
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

  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  // Step 1: Vault server config must save first.
  await expect(page.locator('#tab-panel-vault')).toBeVisible();
  await page.locator('input[name="nfse[bao_addr]"]').fill('http://openbao:8200');
  await page.locator('input[name="nfse[bao_mount]"]').fill('/nfse');
  await page.locator('input[name="nfse[bao_token]"]').fill('dev-only-root-token');
  await page.locator('#tab-panel-vault button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/1\/nfse\/settings\?tab=vault/);

  // Step 2: Certificate depends on Vault; failed upload must stay in settings and tab 2.
  await openTab(page, '#tab-btn-certificate', '#tab-panel-certificate');

  const showReplaceButton = page.locator('#btn-show-replace-cert');
  if (await showReplaceButton.count()) {
    await showReplaceButton.click();
  }

  await page.locator('input[name="pfx_file"]').setInputFiles('e2e/fixtures/test-cert.p12');
  await page.locator('input[name="pfx_password"]').fill('wrong-password');
  await page.locator('#btn-upload-cert').click();
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/1\/nfse\/settings\?tab=certificate/);
  await expect(page).not.toHaveURL(/\/1\/nfse\/ibge\/municipalities\//);
  await expect(page.locator('#tab-panel-certificate')).toBeVisible();

  // Step 3: Fiscal settings depends on previous steps being available.
  await openTab(page, '#tab-btn-fiscal', '#tab-panel-fiscal');
  await page.locator('select[name="nfse[uf]"]').selectOption('SP');
  await page.locator('select[name="nfse[municipio_nome]"]').selectOption('Sao Paulo');
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toHaveValue('3550308');
  await page.locator('#tab-panel-fiscal button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/1\/nfse\/settings\?tab=fiscal/);

  // Step 4: Services tab becomes part of the same guided setup flow.
  await openTab(page, '#tab-btn-services', '#tab-panel-services');
  await expect(page.locator('#services-filter-form')).toBeVisible();
  await expect(page.locator('a[href*="/nfse/settings/services/create"]')).toBeVisible();
});

test('service create LC116 selection does not navigate to JSON endpoint', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  const generatedCode = String(Date.now()).slice(-4).padStart(4, '0');
  const generatedNbs = `${generatedCode}01`;
  const generatedLabel = `${generatedCode} - Servico E2E ${generatedCode}`;

  await page.route('**/nfse/lc116/services**', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          {
            code: generatedCode,
            display_code: generatedCode,
            description: `Servico E2E ${generatedCode}`,
            label: generatedLabel,
          },
        ],
      }),
    });
  });

  await page.goto('/1/nfse/settings/services/create', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const lcDisplay = page.locator('#item_lista_servico_display');
  await lcDisplay.fill('servico e2e');
  await page.waitForTimeout(400);
  await lcDisplay.fill(generatedLabel);
  await lcDisplay.press('Tab');

  await expect(page.locator('#item_lista_servico')).toHaveValue(generatedCode);
  await expect(page).not.toHaveURL(/\/1\/nfse\/lc116\/services/);
  await expect(page.locator('body')).not.toContainText('{"data":[');

  await lcDisplay.focus();
  await lcDisplay.press('Enter');
  await expect(page).toHaveURL(/\/1\/nfse\/settings\/services\/create/);
  await expect(page).not.toHaveURL(/\/1\/nfse\/lc116\/services/);
  await expect(page.locator('body')).not.toContainText('{"data":[');
});

