// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, type Page } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

const company = (id: number) => ({ company_id: id, name: 'Empresa sintética ' + id });
const profile = (id: number) => ({
  ...company(id), status: 'active', cleanup_pending: false,
  certificate_valid_from: 1, certificate_valid_to: 1900000000,
  fiscal_parameters: { uf: 'RJ', municipio_ibge: '3304557', emission_policy: 'manual' },
  fiscal_readiness: { basic_complete: false, pending: ['opcao_simples_nacional'], municipal_review_required: false },
});

async function mockApi(page: Page, profiles: any[], eligible: any[], vaultState = 'operational') {
  let currentProfiles = profiles;
  let currentEligible = eligible;
  await page.route('**/nfse/settings/vault/diagnostics', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ state: vaultState, reason: vaultState === 'operational' ? 'verified' : 'network_failure',
      capabilities: { network: vaultState === 'operational', authentication: vaultState === 'operational',
        kv_v2: vaultState === 'operational', certificate_read: vaultState === 'operational',
        certificate_write: vaultState === 'operational' } }),
  }));
  await page.route('**/nfse/settings/company-profiles**', async (route) => {
    const request = route.request();
    const method = request.method();
    const url = request.url();
    if (method === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ profiles: currentProfiles, eligible_companies: currentEligible }) });
      return;
    }
    if (method === 'DELETE') {
      const id = Number(url.match(/company-profiles\/(\d+)/)?.[1]);
      const selected = currentProfiles.find((p) => p.company_id === id);
      currentProfiles = currentProfiles.filter((p) => p.company_id !== id);
      if (selected) currentEligible.push(company(id));
      await route.fulfill({ status: 204 });
      return;
    }
    if (method === 'POST' && url.endsWith('/company-profiles')) {
      const id = Number(request.postData()?.match(/name="company_id"[\s\S]*?\r?\n\r?\n(\d+)/)?.[1]);
      if (id && currentEligible.some((p) => p.company_id === id)) {
        currentProfiles.push(profile(id));
        currentEligible = currentEligible.filter((p) => p.company_id !== id);
        await route.fulfill({ status: 201, contentType: 'application/json',
          body: JSON.stringify({ data: profile(id) }) });
      } else {
        await route.fulfill({ status: 409, contentType: 'application/json',
          body: JSON.stringify({ message: 'Empresa indisponível' }) });
      }
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: profile(1) }) });
  });
}

test('eligibility, fiscal pendencies, Vault outage and native administration', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], [company(2)], 'failed');
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(1);
  await expect(page.locator('#profiles-body')).toContainText('Empresa sintética 1');
  await expect(page.locator('#profiles-body')).toContainText('Enquadramento do Simples Nacional');
  await expect(page.locator('#profile-add')).toBeDisabled();
  await expect(page.locator('#vault-status')).toContainText('Falha');
  await expect(page.locator('#vault-diagnostics')).not.toHaveAttribute('open');
  await page.locator('#vault-diagnostics summary').click();
  await expect(page.locator('#vault-check')).toBeVisible();
  await expect(page.locator('#profiles-body')).toContainText('Editar');
});

test('only available native company appears, and successful deletion makes it eligible', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], [company(2)]);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeEnabled();
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-company option')).toHaveCount(1);
  await expect(page.locator('#profile-company')).toHaveValue('2');
  await page.locator('#profile-cancel').click();
  page.on('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Excluir' }).click();
  await expect(page.locator('#profiles-body tr')).toHaveCount(0);
  await expect(page.locator('#profile-add')).toBeEnabled();
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-company option')).toHaveCount(2);
});

test('no eligible company hides Add and guides native Akaunting company creation', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeHidden();
  await expect(page.locator('#profiles-empty')).toContainText('Cadastre uma empresa no Akaunting');
});

test('edit remains available without Vault and never transfers company identity', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], [], 'failed');
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Editar' }).click();
  await expect(page.locator('#profile-company')).toBeDisabled();
  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-pfx')).not.toHaveAttribute('required');
});

test('administrative UI synthetic screenshot', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], [company(2)]);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeEnabled();
  await info.attach('nfse-company-admin-synthetic.png', {
    body: await page.locator('#nfse-company-admin').screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  });
});
