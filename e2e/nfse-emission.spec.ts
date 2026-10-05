// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

const REAL_EMIT_FLOW_ENABLED = process.env.NFSE_E2E_REAL_EMIT_FLOW === '1';
const KNOWN_INVALID_CUSTOMERS = new Set(['librecode']);
const RETRYABLE_GATEWAY_CODES = /(E0084|E0202|E0700)/i;
const EXAMPLE_FEDERAL_PROFILE = {
  federalPiscofinsSituacaoTributaria: '1',
  federalPiscofinsTipoRetencao: '4',
  federalPiscofinsAliquotaPis: '0.65',
  federalPiscofinsAliquotaCofins: '3.00',
  federalValorIrrf: '2.00',
  federalValorCsll: '1.00',
  federalValorCp: '0.50',
  tributosFedP: '3.65',
  tributosEstP: '0.00',
  tributosMunP: '2.00',
};

test.use({ serviceWorkers: 'block' });

const emitFormsSelector = "form[action*='/nfse/invoices/'][action$='/emit']";

function normalizeDecimal(value: string | null | undefined): string {
  const raw = (value ?? '').trim();

  if (raw === '') {
    return '';
  }

  const cleaned = raw.replace(/\s+/g, '').replace(/R\$/g, '').replace(/%/g, '');

  if (cleaned.includes(',') && cleaned.includes('.')) {
    return Number(cleaned.replace(/\./g, '').replace(',', '.')).toFixed(2);
  }

  if (cleaned.includes(',')) {
    return Number(cleaned.replace(',', '.')).toFixed(2);
  }

  return Number(cleaned).toFixed(2);
}

function isEligiblePendingInvoice(customerName: string, invoiceAmount: string): boolean {
  const normalizedCustomer = customerName.trim().toLowerCase();

  if (KNOWN_INVALID_CUSTOMERS.has(normalizedCustomer)) {
    return false;
  }

  // Ensure invoice amount is enough to exercise the configured federal profile.
  return Number(invoiceAmount) >= 50.00;
}

async function applyExampleFederalProfile(page): Promise<void> {
  await page.goto('/1/nfse/settings?tab=federal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#tab-panel-federal')).toBeVisible();

  await page.locator('#federal-piscofins-situacao').selectOption(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsSituacaoTributaria);
  await page.locator('#federal-piscofins-tipo-retencao').selectOption(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsTipoRetencao);
  await page.locator('#federal_piscofins_aliquota_pis').fill(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsAliquotaPis);
  await page.locator('#federal_piscofins_aliquota_cofins').fill(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsAliquotaCofins);
  await page.locator('#federal_valor_irrf').fill(EXAMPLE_FEDERAL_PROFILE.federalValorIrrf);
  if (await page.locator('#federal-valor-csll-row').isVisible()) {
    await page.locator('#federal_valor_csll').fill(EXAMPLE_FEDERAL_PROFILE.federalValorCsll);
  }
  await page.locator('#federal_valor_cp').fill(EXAMPLE_FEDERAL_PROFILE.federalValorCp);
  await page.locator('#tributos_fed_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosFedP);
  await page.locator('#tributos_est_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosEstP);
  await page.locator('#tributos_mun_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosMunP);
  await page.locator('#tributos_fed_sn').fill('0.00');
  await page.locator('#tributos_est_sn').fill('0.00');
  await page.locator('#tributos_mun_sn').fill('0.00');

  await page.locator('#tab-panel-federal button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/1\/nfse\/settings/);
}

test('pending native invoice exposes fiscal emission action', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${invoiceId}// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

const REAL_EMIT_FLOW_ENABLED = process.env.NFSE_E2E_REAL_EMIT_FLOW === '1';
const KNOWN_INVALID_CUSTOMERS = new Set(['librecode']);
const RETRYABLE_GATEWAY_CODES = /(E0084|E0202|E0700)/i;
const EXAMPLE_FEDERAL_PROFILE = {
  federalPiscofinsSituacaoTributaria: '1',
  federalPiscofinsTipoRetencao: '4',
  federalPiscofinsAliquotaPis: '0.65',
  federalPiscofinsAliquotaCofins: '3.00',
  federalValorIrrf: '2.00',
  federalValorCsll: '1.00',
  federalValorCp: '0.50',
  tributosFedP: '3.65',
  tributosEstP: '0.00',
  tributosMunP: '2.00',
};

test.use({ serviceWorkers: 'block' });

const emitFormsSelector = "form[action*='/nfse/invoices/'][action$='/emit']";

function normalizeDecimal(value: string | null | undefined): string {
  const raw = (value ?? '').trim();

  if (raw === '') {
    return '';
  }

  const cleaned = raw.replace(/\s+/g, '').replace(/R\$/g, '').replace(/%/g, '');

  if (cleaned.includes(',') && cleaned.includes('.')) {
    return Number(cleaned.replace(/\./g, '').replace(',', '.')).toFixed(2);
  }

  if (cleaned.includes(',')) {
    return Number(cleaned.replace(',', '.')).toFixed(2);
  }

  return Number(cleaned).toFixed(2);
}

function isEligiblePendingInvoice(customerName: string, invoiceAmount: string): boolean {
  const normalizedCustomer = customerName.trim().toLowerCase();

  if (KNOWN_INVALID_CUSTOMERS.has(normalizedCustomer)) {
    return false;
  }

  // Ensure invoice amount is enough to exercise the configured federal profile.
  return Number(invoiceAmount) >= 50.00;
}

async function applyExampleFederalProfile(page): Promise<void> {
  await page.goto('/1/nfse/settings?tab=federal', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  await expect(page.locator('#tab-panel-federal')).toBeVisible();

  await page.locator('#federal-piscofins-situacao').selectOption(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsSituacaoTributaria);
  await page.locator('#federal-piscofins-tipo-retencao').selectOption(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsTipoRetencao);
  await page.locator('#federal_piscofins_aliquota_pis').fill(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsAliquotaPis);
  await page.locator('#federal_piscofins_aliquota_cofins').fill(EXAMPLE_FEDERAL_PROFILE.federalPiscofinsAliquotaCofins);
  await page.locator('#federal_valor_irrf').fill(EXAMPLE_FEDERAL_PROFILE.federalValorIrrf);
  if (await page.locator('#federal-valor-csll-row').isVisible()) {
    await page.locator('#federal_valor_csll').fill(EXAMPLE_FEDERAL_PROFILE.federalValorCsll);
  }
  await page.locator('#federal_valor_cp').fill(EXAMPLE_FEDERAL_PROFILE.federalValorCp);
  await page.locator('#tributos_fed_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosFedP);
  await page.locator('#tributos_est_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosEstP);
  await page.locator('#tributos_mun_p').fill(EXAMPLE_FEDERAL_PROFILE.tributosMunP);
  await page.locator('#tributos_fed_sn').fill('0.00');
  await page.locator('#tributos_est_sn').fill('0.00');
  await page.locator('#tributos_mun_sn').fill('0.00');

  await page.locator('#tab-panel-federal button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/1\/nfse\/settings/);
}

));
  await expect(
    page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]'),
  ).toBeVisible();
});

test('[live-fiscal] real happy path emits NFS-e from pending list', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  if (!REAL_EMIT_FLOW_ENABLED) {
    test.skip(true, 'Set NFSE_E2E_REAL_EMIT_FLOW=1 to run real emission happy path.');
  }

  await loginToAkaunting(page, testInfo);

  await applyExampleFederalProfile(page);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const emitButtons = page.locator(`${emitFormsSelector} button[type='submit']`);
  const emitButtonsCount = await emitButtons.count();

  if (emitButtonsCount === 0) {
    test.skip(true, 'No pending invoices available to execute real emission happy path.');
  }

  const attemptedInvoices = new Set<string>();
  let emittedSuccessfully = false;
  let lastGatewayDetail = '';

  for (let attempt = 0; attempt < emitButtonsCount; attempt += 1) {
    const emitForms = page.locator(emitFormsSelector);
    const currentButtons = page.locator(`${emitFormsSelector} button[type='submit']`);
    const currentCount = await currentButtons.count();

    let selectedIndex = -1;
    let invoiceId = '';
    let invoiceAmount = '';

    for (let index = 0; index < currentCount; index += 1) {
      const candidateForm = emitForms.nth(index);
      const emitAction = await candidateForm.getAttribute('action');
      const invoiceIdMatch = emitAction?.match(/\/invoices\/(\d+)\/emit$/);
      const candidateInvoiceId = invoiceIdMatch?.[1] ?? '';

      if (candidateInvoiceId === '' || attemptedInvoices.has(candidateInvoiceId)) {
        continue;
      }

      const candidateRow = candidateForm.locator('xpath=ancestor::tr[1]');
      const customerName = (await candidateRow.locator('td').nth(1).innerText()).trim();
      const candidateAmount = normalizeDecimal(await candidateRow.locator('td').nth(2).innerText());

      if (!isEligiblePendingInvoice(customerName, candidateAmount)) {
        continue;
      }

      selectedIndex = index;
      invoiceId = candidateInvoiceId;
      invoiceAmount = candidateAmount;
      break;
    }

    if (selectedIndex === -1) {
      break;
    }

    attemptedInvoices.add(invoiceId);

    const emitButton = currentButtons.nth(selectedIndex);
    await expect(emitButton).toBeVisible();

    if (!(await emitButton.isEnabled())) {
      const readinessItems = await page
        .locator('li')
        .filter({ hasText: /configured|configurado|ready|pront/i })
        .allInnerTexts();

      const details = readinessItems.length > 0 ? readinessItems.join('; ') : 'unknown configuration prerequisite';

      throw new Error(`Real emission blocked by pending settings: ${details}`);
    }

    await emitButton.click();
    await page.waitForLoadState('networkidle');

    if (/\/1\/nfse\/invoices\/pending$/.test(page.url())) {
      const bodyText = await page.locator('body').innerText();
      const errorDetail = bodyText.match(/Detalhe SEFIN:[\s\S]*/i)?.[0] ?? 'unknown gateway detail';
      lastGatewayDetail = errorDetail;

      if (RETRYABLE_GATEWAY_CODES.test(errorDetail)) {
        await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle');
        continue;
      }

      throw new Error(`Real emission remained on pending list after using expected federal payload: ${errorDetail}`);
    }

    await expect(page).toHaveURL(/\/1\/nfse\/invoices\/\d+$/);
    await expect(page.locator('body')).toContainText(/(emitida com sucesso|emitted successfully)/i);
    await expect(page.locator('body')).toContainText(/(dados da nfs-e|nfs-e data)/i);
    emittedSuccessfully = true;
    break;
  }

  if (!emittedSuccessfully) {
    if (lastGatewayDetail === '' || RETRYABLE_GATEWAY_CODES.test(lastGatewayDetail)) {
      test.skip(true, `Emission blocked by external SEFIN business constraint: ${lastGatewayDetail || 'no detail returned'}`);
    }

    throw new Error(`Could not emit any eligible pending invoice. Last gateway detail: ${lastGatewayDetail || 'none'}`);
  }
});
