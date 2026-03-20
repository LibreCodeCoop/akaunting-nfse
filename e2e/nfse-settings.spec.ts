// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('NFS-e settings screen is reachable and visible', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/nfse/settings', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/nfse\/settings/);
  await expect(page.getByRole('heading', { name: /NFS-e/i })).toBeVisible();
  await expect(page.locator('input[name="nfse[cnpj_prestador]"]')).toBeVisible();
  await expect(page.locator('input[name="nfse[municipio_ibge]"]')).toBeVisible();
});
