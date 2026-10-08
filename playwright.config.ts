// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig } from '@playwright/test';

const baseURL = process.env.NFSE_E2E_BASE_URL ?? 'http://localhost:8082';

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  timeout: 60_000,
  retries: 0,
  workers: process.env.CI ? 1 : undefined,
  projects: [
    {
      name: 'smoke',
      retries: process.env.CI ? 1 : 0,
      testMatch: ['smoke.spec.ts', 'deterministic-harness.spec.ts'],
    },
    {
      name: 'full-ui',
      testMatch: [
        'nfse-accessibility.spec.ts',
        'nfse-adn-import.spec.ts',
        'nfse-bulk-emission.spec.ts',
        'nfse-certificate.spec.ts',
        'nfse-emission.spec.ts',
        'nfse-grouped-invoice.spec.ts',
        'nfse-invoice-show-emit-modal.spec.ts',
        'nfse-item-validation.spec.ts',
        'nfse-settings.spec.ts',
        'nfse-substitution.spec.ts',
        'nfse-visual-regression.spec.ts',
      ],
      grepInvert: /\[live-fiscal\]/,
    },
    {
      name: 'live-fiscal',
      testMatch: [
        'live-fiscal.spec.ts',
        'nfse-emission.spec.ts',
        'nfse-reemit-status.spec.ts',
      ],
      grep: /\[live-fiscal\]/,
    },
  ],
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: process.env.CI ? 'off' : 'retain-on-failure',
    launchOptions: process.env.CI
      ? { args: ['--disable-dev-shm-usage'] }
      : undefined,
  },
  webServer: undefined,
});
