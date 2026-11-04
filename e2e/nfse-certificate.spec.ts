// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('selected company certificate form binds PFX to current Akaunting company', async ({ page }, info) => {
  await loginToAkaunting(page, info);
  await page.route('**/nfse/settings/company-profiles', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ profiles: [], eligible_companies: [{company_id: 1, name: 'Empresa sintética 1'}] }),
  }));
  await page.route('**/nfse/settings/vault/diagnostics', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({state: 'operational', reason: 'verified', capabilities: {}}),
  }));
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#profile-add')).toBeEnabled();
  await page.locator('#profile-add').click();
  await expect(page.locator('#profile-company')).toHaveValue('1');
  await expect(page.locator('#profile-company')).toHaveAttribute('type', 'hidden');
  await expect(page.locator('#profile-pfx')).toHaveAttribute('accept', '.pfx,.p12');
  await expect(page.locator('#profile-pfx-password')).toHaveAttribute('type', 'password');
  await expect(page.locator('#profile-pfx')).toHaveAttribute('required');
  await page.locator('#profile-cancel').click();
});
