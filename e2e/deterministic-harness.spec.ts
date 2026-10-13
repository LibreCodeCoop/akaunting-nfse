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

// Synthetic profile fixture: no real PFX or Vault connectivity, and no
// company-scoped legacy nfse.* values are used as configuration.
async function openFiscalProfile(page: import('@playwright/test').Page) {
  await page.route('**/nfse/settings/company-profiles', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      profiles: [{
        company_id: 1, name: 'Prestador sintético',
        status: 'active', cleanup_pending: false,
        certificate_cnpj: '11222333000181',
        fiscal_parameters: {
          uf: 'RJ', municipio_ibge: '3303302',
          opcao_simples_nacional: '1', tributacao_issqn: '1',
          tipo_retencao_iss: '1', regime_especial_tributacao: '0',
          emission_policy: 'manual',
        },
        fiscal_readiness: { basic_complete: true, pending: [], municipal_review_required: false },
      }],
      eligible_companies: [],
    }),
  }));
  await page.route('**/nfse/settings/vault/diagnostics', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ state: 'operational', reason: 'verified', capabilities: {} }),
  }));
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(1);
  await page.locator('#profiles-body').getByRole('button', { name: 'Editar' }).click();
  await expect(page.locator('#profile-editor')).toBeVisible();
}

test('deterministic fiscal profile displays synthetic company and municipal identity', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await openFiscalProfile(page);

  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-company')).toBeDisabled();
  await expect(page.locator('#profile-municipality')).toHaveValue('3303302');
  await expect(page.locator('#profile-simples')).toHaveValue('1');
  await expect(page.locator('#profile-regime')).toHaveValue('0');
});

test('special tax regime stays visible but invalid across incompatible Simples or ISSQN transitions', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await openFiscalProfile(page);

  const simples = page.locator('#profile-simples');
  const issqn = page.locator('#profile-issqn');
  const regime = page.locator('#profile-regime');

  await expect(regime).toHaveValue('0');
  await simples.selectOption('1');
  await issqn.selectOption('1');
  await regime.selectOption('6');
  await expect(regime).toHaveValue('6');

  await simples.selectOption('2');
  await expect(regime).toHaveValue('6');
  await expect(regime).toHaveAttribute('aria-invalid', 'true');
  await expect(regime.locator('option[value="9"]')).toBeDisabled();
  await regime.selectOption('0');
  await expect(regime).toHaveAttribute('aria-invalid', 'false');

  await issqn.selectOption('3');
  await expect(regime.locator('option[value="6"]')).toBeDisabled();
  await expect(regime).toHaveValue('0');
});

test('ME/EPP apuration enables eligible special regimes without clearing saved choices', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await openFiscalProfile(page);
  const simples = page.locator('#profile-simples');
  const apuration = page.locator('#profile-apuracao');
  const regime = page.locator('#profile-regime');

  await simples.selectOption('1');
  await expect(apuration).toBeDisabled();
  await simples.selectOption('3');
  await expect(apuration).toBeVisible();
  await expect(apuration).toBeEnabled();
  await expect(apuration).toHaveValue('');
  await regime.selectOption('0');
  await apuration.selectOption('1');
  await expect(regime.locator('option[value="9"]')).toBeDisabled();
  await apuration.selectOption('2');
  await expect(regime.locator('option[value="9"]')).toBeEnabled();
  await regime.selectOption('9');
  await apuration.selectOption('1');
  await expect(regime).toHaveValue('9');
  await expect(regime).toHaveAttribute('aria-invalid', 'true');
  await apuration.selectOption('3');
  await expect(regime).toHaveAttribute('aria-invalid', 'false');

  await simples.selectOption('2');
  await expect(apuration).toBeDisabled();
  await simples.selectOption('3');
  await expect(apuration).toHaveValue('3');
});
