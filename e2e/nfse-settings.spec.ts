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
  await page.route('**/nfse/settings/vault', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      addr: 'https://vault.example.test', mount: '/nfse', auth_mode: 'token',
      ready: true, configuration_error: false,
    } }),
  }));
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
  await mockApi(page, [profile(1)], [], 'failed');
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(1);
  await expect(page.locator('#profiles-body')).toContainText('Empresa sintética 1');
  await expect(page.locator('#profiles-body')).toContainText('Enquadramento do Simples Nacional');
  await expect(page.locator('#profile-add')).toBeHidden();
  await expect(page.locator('#vault-status')).toContainText('Falha');
  await expect(page.locator('#vault-diagnostics')).not.toHaveAttribute('open');
  await page.locator('#vault-diagnostics summary').click();
  await expect(page.locator('#vault-check')).toBeVisible();
  await expect(page.locator('#profiles-body')).toContainText('Editar');
});

test('current company only: deleting a profile restores its setup action', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeHidden();
  page.on('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /Excluir configuração NFS-e de Empresa sintética 1/ }).click();
  await expect(page.locator('#profiles-body tr')).toHaveCount(0);
  await expect(page.locator('#profile-add')).toBeEnabled();
  await expect(page.locator('#profile-add')).toHaveText('Configurar NFS-e');
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-company')).toHaveAttribute('type', 'hidden');
  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-company-name')).toHaveText('Empresa sintética 1');
  await expect(page.locator('#profile-form select[name="company_id"]')).toHaveCount(0);
});

test('no eligible company hides setup and explains selected-company access', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeHidden();
  await expect(page.locator('#profiles-empty')).toContainText('Empresa indisponível para configuração NFS-e');
});

test('edit remains available without Vault and never transfers company identity', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], [], 'failed');
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.locator('#profiles-body').getByRole('button', { name: 'Editar' }).click();
  await expect(page.locator('#profile-company')).toHaveAttribute('type', 'hidden');
  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-pfx')).not.toHaveAttribute('required');
});

test('administrative UI synthetic screenshot', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(1);
  await info.attach('nfse-company-admin-synthetic.png', {
    body: await page.locator('#nfse-company-admin').screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  });
});

test('fiscal tax associations are reviewed through native settings, without automatic provisioning', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });

  const link = page.getByRole('link', { name: 'Revisar associações fiscais dos impostos existentes' });
  await expect(link).toBeVisible();
  await link.click();

  await expect(page).toHaveURL(/\/1\/nfse\/settings\/tax-associations$/);
  await expect(page.getByRole('heading', { name: 'Associações fiscais dos impostos existentes' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Impostos cadastrados nesta empresa' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Abrir cadastro de impostos do Akaunting' })).toBeVisible();
  await expect(page.getByText('A classificação não calcula', { exact: false })).toBeVisible();
});


test('profile list failure hides tenant data and creation controls', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(1);
  await page.route('**/nfse/settings/company-profiles', route => route.fulfill({
    status: 403, contentType: 'application/json',
    body: JSON.stringify({ message: 'synthetic secret never shown' }),
  }));
  page.on('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /Excluir configuração NFS-e de Empresa sintética 1/ }).click();
  await expect(page.locator('#profiles-error')).toContainText('não autorizado');
  await expect(page.locator('#profile-add')).toBeHidden();
  await expect(page.locator('#profiles-table-wrap')).toBeHidden();
  await expect(page.locator('#profiles-error')).not.toContainText('synthetic secret');
});

test('profile editor restores focus on cancel and displays sanitized validation errors', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], []);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  const edit = page.getByRole('button', { name: /Editar configuração NFS-e de Empresa sintética 1/ });
  await edit.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#profile-editor-title')).toBeFocused();
  await page.locator('#profile-cancel-bottom').click();
  await expect(edit).toBeFocused();
  await edit.click();
  await expect(page.locator('#profile-editor-title')).toBeFocused();
  await page.route('**/nfse/settings/company-profiles/1', route => route.fulfill({
    status: 422, contentType: 'application/json',
    body: JSON.stringify({ errors: { 'fiscal_parameters.uf': ['UF sintética inválida'] } }),
  }));
  await page.locator('#profile-save').click();
  await expect(page.locator('#profile-form-error')).toContainText('UF sintética inválida');
  await expect(page.locator('#profile-form-error')).toBeFocused();
  await expect(page.locator('#profile-save')).toBeEnabled();
  await page.locator('#profile-cancel').click();
});

test('Vault failure opens corrective infrastructure settings instead of inert setup', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [], [company(1)], 'failed');
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  const setup = page.locator('#profile-add');
  await expect(setup).toBeEnabled();
  await expect(setup).toHaveText('Configurar infraestrutura primeiro');
  await expect(page.locator('#profile-add-hint')).toContainText('Vault não está operacional');
  await setup.click();
  await expect(page.locator('#vault-editor')).toBeVisible();
  await expect(page.locator('#bao_addr')).toBeFocused();
  await expect(page.locator('#profile-editor')).toBeHidden();
});

test('a response containing another company cannot pollute the selected settings page', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(2)], [company(2)]);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profiles-body tr')).toHaveCount(0);
  await expect(page.locator('#profile-add')).toBeHidden();
  await expect(page.locator('#profiles-empty')).toContainText('Empresa indisponível');
  await expect(page.locator('#nfse-company-admin')).not.toContainText('Empresa sintética 2');
});

test('the selected Akaunting company is bound automatically, with no duplicate selector', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [], [company(1)]);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-editor')).toBeVisible();
  await expect(page.locator('#profile-company')).toHaveAttribute('type', 'hidden');
  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-company-name')).toHaveText('Empresa sintética 1');
  await expect(page.locator('select[name="company_id"]')).toHaveCount(0);
});

test('Vault infrastructure reads and saves through the same JSON contract as native API clients', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await mockApi(page, [profile(1)], []);
  let submitted = false;
  await page.route('**/nfse/settings/vault', async route => {
    const req = route.request();
    if (req.method() === 'POST') {
      submitted = true;
      expect(req.headers().accept).toContain('application/json');
      expect(req.postData()).toContain('name="_method"');
      expect(req.postData()).toContain('PATCH');
    }
    await route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        addr: 'https://vault.example.test', mount: '/nfse',
        auth_mode: 'token', ready: true, configuration_error: false,
      } }),
    });
  });
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#vault-summary')).toContainText('vault.example.test');
  await expect(page.locator('#bao_addr')).toHaveValue('https://vault.example.test');
  await expect(page.locator('#vault-summary')).not.toContainText('never-expose');
  await page.locator('#vault-edit').click();
  await page.locator('#bao_token').fill('synthetic-never-expose');
  await page.locator('#vault-form button[type="submit"]').click();
  await expect(page.locator('#vault-form-feedback')).toContainText('Infraestrutura salva');
  expect(submitted).toBe(true);
  await expect(page.locator('#bao_token')).toHaveValue('');
  await expect(page.locator('#nfse-company-admin')).not.toContainText('synthetic-never-expose');
});
