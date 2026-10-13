// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('multi-company certificate form accepts PFX/P12 with password and owner lock', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await page.route('**/nfse/settings/company-profiles', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ profiles: [{
      company_id: 1, name: 'Empresa sintética 1', status: 'active', cleanup_pending: false,
      fiscal_parameters: {}, fiscal_readiness: {basic_complete: false, pending: ['uf']},
    }], eligible_companies: [{company_id: 2, name: 'Empresa sintética 2'}] }),
  }));
  await page.route('**/nfse/settings/vault/diagnostics', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({state: 'operational', reason: 'verified', capabilities: {}}),
  }));
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeEnabled();
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-company')).toHaveValue('2');
  await expect(page.locator('#profile-pfx')).toHaveAttribute('accept', '.pfx,.p12');
  await expect(page.locator('#profile-pfx-password')).toHaveAttribute('type', 'password');
  await expect(page.locator('#profile-pfx')).toHaveAttribute('required');
  await page.locator('#profile-cancel').click();
  await page.getByRole('button', {name: 'Editar'}).click();
  await expect(page.locator('#profile-company')).toBeDisabled();
  await expect(page.locator('#profile-pfx')).not.toHaveAttribute('required');
  await expect(page.locator('#profile-rotation-hint')).toBeVisible();
});
