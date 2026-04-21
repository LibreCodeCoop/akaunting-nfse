// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test, type Locator, type Page } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

function extractPayloadValue(payload: string, fieldName: string): string | null {
  const encoded = new URLSearchParams(payload);

  if (encoded.has(fieldName)) {
    return encoded.get(fieldName);
  }

  if (encoded.has(`${fieldName}[0]`)) {
    return encoded.get(`${fieldName}[0]`);
  }

  const escapedField = fieldName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const escapedFieldArray = `${escapedField}\\[0\\]`;
  const multipartPatterns = [
    new RegExp(`name=\\"${escapedField}\\"\\r?\\n\\r?\\n([\\s\\S]*?)\\r?\\n--`, 'm'),
    new RegExp(`name=\\"${escapedFieldArray}\\"\\r?\\n\\r?\\n([\\s\\S]*?)\\r?\\n--`, 'm'),
  ];

  const match = multipartPatterns
    .map((pattern) => payload.match(pattern))
    .find((value) => value !== null);

  if (!match || typeof match[1] !== 'string') {
    return null;
  }

  return match[1].trim();
}

async function getEditorText(editor: Locator): Promise<string> {
  return editor.evaluate((node: HTMLElement) => (node.textContent || '').replace(/\u00a0/g, ' ').trim());
}

async function clickRestoreButton(button: Locator): Promise<void> {
  await button.evaluate((node: HTMLButtonElement) => node.click());
}

async function openEmitActionFromInvoiceShow(page: Page): Promise<boolean> {
  const inlineEmitButton = page.locator('#show-slider-actions-send-email-invoice:visible');

  if (await inlineEmitButton.count() > 0) {
    await inlineEmitButton.first().click();

    return true;
  }

  const moreActionsButton = page.getByRole('button', { name: 'more_horiz' });

  if (await moreActionsButton.count() === 0) {
    return false;
  }

  await moreActionsButton.first().click();

  const moreMenuEmitButton = page
    .locator('#show-more-actions-send-email-invoice:visible, button:visible')
    .filter({ hasText: /Emitir NFS-e agora|Emit NFS-e now/i })
    .last();

  if (await moreMenuEmitButton.count() === 0) {
    return false;
  }

  await moreMenuEmitButton.click({ force: true });

  return true;
}

test('invoice show emit button opens NFS-e modal and submits final emit payload', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/nfse\/invoices(?:\/pending|\?status=pending.*)?$/);
  await expect(page.locator('body')).toBeVisible();

  const candidateIds = await page.locator("form[action*='/nfse/invoices/'][action$='/emit']").evaluateAll((forms) => {
    const ids: string[] = [];

    for (const form of forms) {
      const action = form.getAttribute('action') ?? '';
      const match = action.match(/\/invoices\/(\d+)\/emit$/);

      if (match && match[1]) {
        ids.push(match[1]);
      }

      if (ids.length >= 10) {
        break;
      }
    }

    return ids;
  });

  if (candidateIds.length === 0) {
    test.skip(true, 'No pending invoice available to validate emit modal flow from invoice page.');
  }

  let selectedInvoiceId = '';
  let modalCreatePayload: { data?: { title?: string }; html?: string } | null = null;
  let dialog = page.locator('[role="dialog"]').last();

  for (const invoiceId of candidateIds) {
    await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${invoiceId}$`));

    const modalCreateResponsePromise = page.waitForResponse((response) => {
      return response.request().method() === 'GET' && response.url().includes(`/nfse/modals/invoices/${invoiceId}/emails/create`);
    }, { timeout: 8_000 }).catch(() => null);

    const clicked = await openEmitActionFromInvoiceShow(page);

    if (!clicked) {
      continue;
    }

    const modalCreateResponse = await modalCreateResponsePromise;

    if (!modalCreateResponse || !modalCreateResponse.ok()) {
      continue;
    }

    const payload = (await modalCreateResponse.json()) as { data?: { title?: string }; html?: string };

    if ((payload.html ?? '').includes('nfse_discriminacao_custom')) {
      selectedInvoiceId = invoiceId;
      modalCreatePayload = payload;
      dialog = page.locator('[role="dialog"]').last();
      break;
    }
  }

  if (selectedInvoiceId === '' || modalCreatePayload === null) {
    test.skip(true, 'Could not find a pending invoice that opens the NFS-e issue modal from invoice show page.');
  }

  const ensuredModalCreatePayload = modalCreatePayload as { data?: { title?: string }; html?: string };

  expect(ensuredModalCreatePayload.data?.title ?? '').toMatch(/NFS-e/i);
  expect(ensuredModalCreatePayload.html ?? '').toContain('nfse_discriminacao_custom');
  expect(ensuredModalCreatePayload.html ?? '').toContain('nfse_send_email');

  await expect(dialog).toBeVisible();
  await expect(dialog.getByText(/Preparar emissao da NFS-e|Prepare NFS-e issuance/i)).toBeVisible();

  const descriptionValue = 'Descricao E2E modal invoice show: preservar campos ao trocar abas.';
  const subjectValue = 'NFS-e E2E Invoice Show {{invoice_number}}';

  await page.locator("textarea[name='nfse_discriminacao_custom']").fill(descriptionValue);

  await dialog.getByText(/E-mail|Email/i).first().click();

  await page.locator('#nfse_send_email_toggle').setChecked(true, { force: true });
  await expect(page.locator("input[name='nfse_email_subject']")).toBeVisible();

  await page.locator("input[name='nfse_email_subject']").fill(subjectValue);

  await page.locator('#nfse_email_save_default_toggle').setChecked(true, { force: true });
  await page.locator('#nfse_email_copy_to_self_toggle').setChecked(true, { force: true });

  await dialog.getByText(/Anexos|Attachments/i).first().click();
  await page.locator('#nfse_email_attach_invoice_pdf_toggle').setChecked(true, { force: true });
  await page.locator('#nfse_email_attach_danfse_toggle').setChecked(true, { force: true });
  await page.locator('#nfse_email_attach_xml_toggle').setChecked(false, { force: true });

  await dialog.getByText(/Geral|General/i).first().click();
  await expect(page.locator("textarea[name='nfse_discriminacao_custom']")).toHaveValue(descriptionValue);

  // Ensure user choices survive tab changes before submit.
  await dialog.getByText(/E-mail|Email/i).first().click();
  await expect(page.locator('#nfse_send_email_toggle')).toBeChecked();
  await expect(page.locator("input[name='nfse_email_subject']")).toHaveValue(subjectValue);
  await expect(page.locator('#nfse_email_save_default_toggle')).toBeChecked();
  await expect(page.locator('#nfse_email_copy_to_self_toggle')).toBeChecked();

  await dialog.getByText(/Anexos|Attachments/i).first().click();
  await expect(page.locator('#nfse_email_attach_invoice_pdf_toggle')).toBeChecked();
  await expect(page.locator('#nfse_email_attach_danfse_toggle')).toBeChecked();
  await expect(page.locator('#nfse_email_attach_xml_toggle')).not.toBeChecked();

  let capturedEmitPayload = '';

  await page.route(new RegExp(`/[0-9]+/nfse/invoices/${selectedInvoiceId}/emit$`), async (route) => {
    const request = route.request();

    if (request.method() !== 'POST') {
      await route.continue();

      return;
    }

    capturedEmitPayload = request.postData() ?? '';

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        error: false,
        message: '',
        redirect: `/1/sales/invoices/${selectedInvoiceId}`,
        data: null,
      }),
    });
  });

  const submitResponsePromise = page.waitForResponse((response) => {
    return response.request().method() === 'POST' && /\/nfse\/invoices\/\d+\/emit$/.test(response.url());
  });

  await dialog.getByRole('button', { name: /Emitir NFS-e agora|Emit NFS-e now/i }).click();

  const submitResponse = await submitResponsePromise;
  expect(submitResponse.ok()).toBeTruthy();

  expect(extractPayloadValue(capturedEmitPayload, 'nfse_discriminacao_custom')).toBe(descriptionValue);
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_subject')).toBe(subjectValue);

  // Boolean fields can be normalized by the generic modal serializer; ensure they are present.
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_send_email')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_save_default')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_copy_to_self')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_invoice_pdf')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_danfse')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_xml')).not.toBeNull();

  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${selectedInvoiceId}$`));
});

test('typing in email body keeps email tab content visible in emit modal', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/nfse\/invoices(?:\/pending|\?status=pending.*)?$/);

  const candidateIds = await page.locator("form[action*='/nfse/invoices/'][action$='/emit']").evaluateAll((forms) => {
    const ids: string[] = [];

    for (const form of forms) {
      const action = form.getAttribute('action') ?? '';
      const match = action.match(/\/invoices\/(\d+)\/emit$/);

      if (match && match[1]) {
        ids.push(match[1]);
      }

      if (ids.length >= 10) {
        break;
      }
    }

    return ids;
  });

  if (candidateIds.length === 0) {
    test.skip(true, 'No pending invoice available to validate email body behavior in emit modal.');
  }

  let selectedInvoiceId = '';
  let dialog = page.locator('[role="dialog"]').last();

  for (const invoiceId of candidateIds) {
    await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${invoiceId}$`));

    const clicked = await openEmitActionFromInvoiceShow(page);

    if (!clicked) {
      continue;
    }

    dialog = page.locator('[role="dialog"]').last();

    if (await dialog.isVisible().catch(() => false)) {
      selectedInvoiceId = invoiceId;
      break;
    }
  }

  if (selectedInvoiceId === '') {
    test.skip(true, 'Could not open emit modal from invoice show page.');
  }

  await expect(dialog).toBeVisible();

  await dialog.getByText(/E-mail|Email/i).first().click();
  await page.locator('#nfse_send_email_toggle').setChecked(true, { force: true });

  const emailPane = dialog.locator('#nfse-tab-pane-email');
  const emailFields = dialog.locator('#nfse-email-fields');
  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ql-editor').first();

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();

  await editor.click({ force: true });
  await page.keyboard.type('Teste E2E: digitar no body deve manter a aba Email visivel.', { delay: 10 });

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();
});

test('restore default button reacts to subject and body edits in emit modal', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  await loginToAkaunting(page, testInfo);

  await page.goto('/1/nfse/invoices/pending', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/nfse\/invoices(?:\/pending|\?status=pending.*)?$/);

  const candidateIds = await page.locator("form[action*='/nfse/invoices/'][action$='/emit']").evaluateAll((forms) => {
    const ids: string[] = [];

    for (const form of forms) {
      const action = form.getAttribute('action') ?? '';
      const match = action.match(/\/invoices\/(\d+)\/emit$/);

      if (match && match[1]) {
        ids.push(match[1]);
      }

      if (ids.length >= 10) {
        break;
      }
    }

    return ids;
  });

  if (candidateIds.length === 0) {
    test.skip(true, 'No pending invoice available to validate restore-default reactivity in emit modal.');
  }

  let selectedInvoiceId = '';
  let dialog = page.locator('[role="dialog"]').last();

  for (const invoiceId of candidateIds) {
    await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${invoiceId}$`));

    const clicked = await openEmitActionFromInvoiceShow(page);

    if (!clicked) {
      continue;
    }

    dialog = page.locator('[role="dialog"]').last();

    if (await dialog.isVisible().catch(() => false)) {
      selectedInvoiceId = invoiceId;
      break;
    }
  }

  if (selectedInvoiceId === '') {
    test.skip(true, 'Could not open emit modal from invoice show page.');
  }

  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await page.locator('#nfse_send_email_toggle').setChecked(true, { force: true });

  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ql-editor').first();
  const restoreButton = dialog.getByRole('button', { name: /Restaurar template padrão|Restore default template/i });
  const initialSubject = await subject.inputValue();
  const initialBodyText = await getEditorText(editor);

  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();
  await expect(restoreButton).toBeHidden();

  await editor.click({ force: true });
  await expect(restoreButton).toBeHidden();

  await subject.fill('Assunto alterado E2E');
  await expect(restoreButton).toBeVisible();

  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();

  await editor.click({ force: true });
  await page.keyboard.type(' Corpo alterado E2E.', { delay: 10 });
  await expect(restoreButton).toBeVisible();

  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();
});

test('restore default flow works on a fixed invoice from show page', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  const invoiceId = (process.env.NFSE_E2E_INVOICE_ID || '').trim();

  if (invoiceId === '') {
    test.skip(true, 'Set NFSE_E2E_INVOICE_ID to run deterministic restore-flow E2E on invoice show page.');
  }

  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${invoiceId}$`));

  await page.evaluate(() => {
    const trigger = document.getElementById('show-slider-actions-send-email-invoice');

    if (trigger) {
      trigger.click();
      return;
    }

    const fallback = Array.from(document.querySelectorAll('button')).find((button) => {
      return /Reemitir NFS-e|Emitir NFS-e agora/i.test((button.textContent || '').trim());
    });

    if (fallback) {
      fallback.click();
    }
  });

  const dialog = page.locator('[role="dialog"]').last();

  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await page.locator('#nfse_send_email_toggle').setChecked(true, { force: true });

  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ql-editor').first();
  const restoreButton = dialog.getByRole('button', { name: /Restaurar template padrão|Restore default template/i });

  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();

  const initialSubject = await subject.inputValue();
  const initialBodyText = await getEditorText(editor);

  await subject.fill('Assunto alterado E2E fixo');
  await expect(restoreButton).toBeVisible();
  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();

  await editor.click({ force: true });
  await page.keyboard.type(' Corpo alterado E2E fixo.', { delay: 10 });
  await expect(restoreButton).toBeVisible();
  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();
});
