// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test('login page is reachable', async ({ page }) => {
  await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/auth\/login$/);
  await expect(page.getByRole('heading', { name: /login to start your session/i })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
});

test('legacy pending invoices route redirects to native Akaunting invoices', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toBeVisible();

  await expect(page).toHaveURL(/\/1\/sales\/invoices(?:\?.*)?$/);
});


test('native fiscal action opens the NFS-e modal', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');
  await expect(emitAction).toBeVisible();
  await emitAction.click();

  await expect(page.locator('[role="dialog"]').last()).toBeVisible();
});


test('post-emission polling unlocks fiscal artifacts after queue completion', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await page.route('**/nfse-test-post-emission-status', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          status: 'completed',
          poll: false,
          stages: {
            artifacts: 'completed',
            email: 'completed',
          },
          artifacts: {
            xml: {
              ready: true,
              download_url: '/1/nfse/invoices/999/artifacts/xml',
            },
            danfse: {
              ready: true,
              download_url: '/1/nfse/invoices/999/artifacts/danfse',
            },
          },
          error: null,
        },
      }),
    });
  });

  const panel = page.locator('#nfse-native-fiscal-panel');
  await expect(panel).toBeVisible();

  await page.evaluate(() => {
    const root = document.querySelector<HTMLElement>('#nfse-native-fiscal-panel');

    if (!root) {
      throw new Error('NFS-e native fiscal panel was not found.');
    }

    root.dataset.nfsePostEmissionStatusUrl = '/nfse-test-post-emission-status';
    root.dataset.nfsePostEmissionState = 'processing';
    root.dataset.messageProcessing = 'Processing';
    root.dataset.messageCompleted = 'Completed';
    root.dataset.messageFailed = 'Failed';
    root.dataset.stageCompleted = 'Completed';

    root.insertAdjacentHTML('beforeend', `
      <div data-nfse-post-emission-box>
        <span data-nfse-post-emission-spinner>spinner</span>
        <span data-nfse-post-emission-message>Processing</span>
        <span data-nfse-email-status>Pending</span>
      </div>
      <a data-nfse-artifact="xml" aria-disabled="true" class="pointer-events-none opacity-60">
        <span data-nfse-artifact-spinner>spinner</span>
        <span data-nfse-artifact-label>XML</span>
      </a>
      <a data-nfse-artifact="danfse" aria-disabled="true" class="pointer-events-none opacity-60">
        <span data-nfse-artifact-spinner>spinner</span>
        <span data-nfse-artifact-label>DANFSE</span>
      </a>
    `);

    const api = (window as typeof window & {
      NfsePostEmissionStatus?: { start: (node: HTMLElement) => void };
    }).NfsePostEmissionStatus;

    if (!api) {
      throw new Error('NFS-e post-emission polling module was not loaded.');
    }

    api.start(root);
  });

  await expect(panel.locator('[data-nfse-post-emission-message]')).toHaveText('Completed');
  await expect(panel.locator('[data-nfse-post-emission-spinner]')).toHaveClass(/hidden/);
  await expect(panel.locator('[data-nfse-artifact="xml"]')).toHaveAttribute('aria-disabled', 'false');
  await expect(panel.locator('[data-nfse-artifact="danfse"]')).toHaveAttribute('aria-disabled', 'false');
});

async function openExistingItemFiscalEditor(page: import('@playwright/test').Page): Promise<void> {
  // Akaunting's plan-limit middleware redirects /common/items/create in the
  // deterministic CI installation. Editing a seeded item exercises the same
  // fiscal fields without bypassing the application's access controls.
  await page.goto('/1/common/items', { waitUntil: 'domcontentloaded' });
  const edit = page.locator('[id^="index-line-actions-edit-item-"]').first();
  await expect(edit).toBeAttached();
  const href = await edit.getAttribute('href');
  expect(href).toBeTruthy();
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/common\/items\/\d+\/edit/);
}

test('tax code assistant searches via debounce and selects both fiscal codes', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  const requests: string[] = [];
  await page.route('**/nfse/national-services?**', async (route) => {
    const url = new URL(route.request().url());
    requests.push(url.searchParams.get('q') ?? '');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [{ code: '010701', description: 'Serviço de teste' }] }),
    });
  });
  await openExistingItemFiscalEditor(page);
  const root = page.locator('[data-nfse-tax-code-assistant]');
  await expect(root).toBeVisible();
  const search = root.locator('[data-nfse-tax-code-query]');
  await search.fill('0');
  await search.fill('01');
  await search.fill('0107');
  await expect(root.locator('[data-nfse-tax-code-results] button')).toHaveCount(1);
  expect(requests.filter(query => query !== '')).toEqual(['0107']);
  await root.locator('[data-nfse-tax-code-results] button').click();
  await expect.poll(() => page.locator('[name="nfse_codigo_tributacao_nacional"]').evaluate(el => (window as any).NfseTaxCodeAssistant.selectedValue(el))).toBe('010701');
  await expect.poll(() => page.locator('[name="nfse_item_lista_servico"]').evaluate(el => (window as any).NfseTaxCodeAssistant.selectedValue(el))).toBe('lc:0107');
});

test('tax code assistant does not display stale search results', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.route('**/nfse/national-services?**', async (route) => {
    const q = new URL(route.request().url()).searchParams.get('q');
    if (q === 'old') {
      await new Promise(resolve => setTimeout(resolve, 800));
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [{ code: q === 'old' ? '010701' : '010702', description: q }] }),
    }).catch(() => {});
  });
  await openExistingItemFiscalEditor(page);
  const root = page.locator('[data-nfse-tax-code-assistant]');
  await expect(root).toBeVisible();
  const search = root.locator('[data-nfse-tax-code-query]');
  await search.fill('old');
  await page.waitForRequest(request => request.url().includes('/nfse/national-services?') && request.url().includes('q=old'));
  await search.fill('new');
  await expect(root.locator('[data-nfse-tax-code-results]')).toContainText('010702');
  await expect(root.locator('[data-nfse-tax-code-results]')).not.toContainText('010701');
});

test('edited fiscal item persists selected LC 116 and cTribNac after reopening', async ({ page }, testInfo) => {
  await loginToAkaunting(page, testInfo);
  await page.route('**/nfse/national-services?**', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [{ code: '010101', description: 'Serviço de teste' }] }),
    });
  });

  await openExistingItemFiscalEditor(page);
  const editUrl = page.url();
  const root = page.locator('[data-nfse-tax-code-assistant]');
  await expect(root).toBeVisible();

  await root.locator('[data-nfse-tax-code-query]').fill('010101');
  await expect(root.locator('[data-nfse-tax-code-results] button')).toHaveCount(1);
  await root.locator('[data-nfse-tax-code-results] button').click();

  const valueOf = (name: string) =>
    page.locator('[name="' + name + '"]').evaluate(el => (window as any).NfseTaxCodeAssistant.selectedValue(el));

  await expect.poll(() => valueOf('nfse_codigo_tributacao_nacional')).toBe('010101');
  await expect.poll(() => valueOf('nfse_item_lista_servico')).toBe('lc:0101');

  const save = page.locator('form#item button[type="submit"]').last();
  await expect(save).toBeEnabled();
  const savedResponse = page.waitForResponse(response =>
    response.url().includes('/common/items/') && ['POST', 'PATCH', 'PUT'].includes(response.request().method()),
  );
  await save.click();
  const result = await savedResponse;
  expect(result.ok()).toBeTruthy();
  // Akaunting redirects after save; response body can be released by Chromium
  // during navigation. Verify persistence by reloading the server-backed form.
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await expect(root).toBeVisible();
  await expect.poll(() => valueOf('nfse_codigo_tributacao_nacional')).toBe('010101');
  await expect.poll(() => valueOf('nfse_item_lista_servico')).toBe('lc:0101');
});
