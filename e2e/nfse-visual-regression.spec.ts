// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createHash } from 'node:crypto';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

type Baselines = Record<string, string>;

async function loadBaselines(): Promise<Baselines> {
  const file = path.join(process.cwd(), 'e2e', 'visual-baselines.json');
  return JSON.parse(await readFile(file, 'utf8')) as Baselines;
}

async function assertVisualBaseline(locator: Locator, key: string): Promise<void> {
  await expect(locator).toBeVisible();

  const outputDir = path.join(process.cwd(), 'test-results', 'visual');
  await mkdir(outputDir, { recursive: true });

  const outputPath = path.join(outputDir, `${key}.png`);
  const png = await locator.screenshot({
    path: outputPath,
    animations: 'disabled',
    caret: 'hide',
  });
  const hash = createHash('sha256').update(png).digest('hex');
  const baselines = await loadBaselines();
  const expected = (baselines[key] ?? '').trim();

  console.log(`VISUAL_BASELINE ${key} ${hash}`);

  expect(
    expected,
    `Missing approved baseline for ${key}. Review test-results/visual/${key}.png and commit the printed SHA-256 only after visual review.`,
  ).not.toBe('');
  expect(
    hash,
    `Visual regression detected for ${key}. Review test-results/visual/${key}.png before updating the baseline hash.`,
  ).toBe(expected);
}

async function openEmissionModal(page: Page): Promise<Locator> {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');
  await expect(emitAction).toBeVisible();
  await emitAction.click();

  const dialog = page.locator('[role="dialog"]').last();
  await expect(dialog).toBeVisible();

  return dialog;
}

test('readiness summary visual baseline', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.goto('/1/nfse/settings', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await assertVisualBaseline(page.locator('#nfse-readiness-summary'), 'settings-readiness-summary');
});

test('emission modal visual baseline', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  const dialog = await openEmissionModal(page);
  const description = dialog.locator("textarea[name='nfse_discriminacao_custom']");
  await description.fill('[0107] Deterministic NFS-e visual fixture.');

  await assertVisualBaseline(dialog.locator('.modal-content').first(), 'emission-modal');
});
