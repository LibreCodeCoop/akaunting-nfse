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

async function setVisibleSwitch(page: Page, id: string, checked: boolean): Promise<void> {
  const input = page.locator(`#${id}`);

  if ((await input.isChecked()) === checked) {
    return;
  }

  await page.locator(`label[for="${id}"]`).click();

  if (checked) {
    await expect(input).toBeChecked();
  } else {
    await expect(input).not.toBeChecked();
  }
}

async function openEmitActionFromInvoiceShow(page: Page): Promise<boolean> {
  const emitAction = page.locator('#nfse-native-fiscal-panel [data-nfse-native-emit="true"]');

  if (await emitAction.count() === 0) {
    return false;
  }

  await expect(emitAction).toBeVisible();
  await emitAction.click();

  return true;
}


test('invoice show emit button opens NFS-e modal and submits final emit payload', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  const fixtureInvoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(fixtureInvoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);

  const selectedInvoiceId = fixtureInvoiceId;
  let modalCreatePayload: { data?: { title?: string }; html?: string } | null = null;
  let dialog = page.locator('[role="dialog"]').last();

  await page.goto(`/1/sales/invoices/${selectedInvoiceId}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${selectedInvoiceId}$`));

  const modalCreateResponsePromise = page.waitForResponse((response) => {
    return response.request().method() === 'GET'
      && response.url().includes(`/nfse/modals/invoices/${selectedInvoiceId}/emails/create`);
  }, { timeout: 8_000 });

  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();

  const modalCreateResponse = await modalCreateResponsePromise;
  expect(modalCreateResponse.ok()).toBeTruthy();

  modalCreatePayload = (await modalCreateResponse.json()) as { data?: { title?: string }; html?: string };
  dialog = page.locator('[role="dialog"]').last();


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

  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);
  await expect(page.locator("input[name='nfse_email_subject']")).toBeVisible();

  await page.locator("input[name='nfse_email_subject']").fill(subjectValue);

  await setVisibleSwitch(page, 'nfse_email_save_default_toggle', true);
  await setVisibleSwitch(page, 'nfse_email_copy_to_self_toggle', true);

  await dialog.getByText(/Anexos|Attachments/i).first().click();
  await setVisibleSwitch(page, 'nfse_email_attach_invoice_pdf_toggle', true);
  await setVisibleSwitch(page, 'nfse_email_attach_danfse_toggle', true);
  await setVisibleSwitch(page, 'nfse_email_attach_xml_toggle', false);

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

  const fixtureInvoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(fixtureInvoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);

  const selectedInvoiceId = fixtureInvoiceId;
  let dialog = page.locator('[role="dialog"]').last();

  await page.goto(`/1/sales/invoices/${selectedInvoiceId}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${selectedInvoiceId}$`));
  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();
  dialog = page.locator('[role="dialog"]').last();


  await expect(dialog).toBeVisible();

  await dialog.getByText(/E-mail|Email/i).first().click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

  const emailPane = dialog.locator('#nfse-tab-pane-email');
  const emailFields = dialog.locator('#nfse-email-fields');
  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ql-editor').first();

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();

  const typedText = 'Teste E2E: digitar no body deve manter a aba Email visivel.';

  await editor.focus();
  await expect.poll(async () => {
    return editor.evaluate((node) => node === document.activeElement || node.contains(document.activeElement));
  }).toBeTruthy();
  await page.keyboard.type(typedText, { delay: 10 });

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();
  await expect.poll(async () => getEditorText(editor)).toContain(typedText);
  await expect.poll(async () => {
    return editor.evaluate((node) => node.contains(document.activeElement));
  }).toBeTruthy();
});

test('restore default button reacts to subject and body edits in emit modal', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  const fixtureInvoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(fixtureInvoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);

  const selectedInvoiceId = fixtureInvoiceId;
  let dialog = page.locator('[role="dialog"]').last();

  await page.goto(`/1/sales/invoices/${selectedInvoiceId}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${selectedInvoiceId}$`));
  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();
  dialog = page.locator('[role="dialog"]').last();


  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ql-editor').first();
  const restoreButton = dialog.getByRole('button', { name: /Restaurar template padrão|Restore default template/i });
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();

  if (await restoreButton.isVisible()) {
    await clickRestoreButton(restoreButton);
  }

  await expect(restoreButton).toBeHidden();

  const initialSubject = await subject.inputValue();
  const initialBodyText = await getEditorText(editor);

  await editor.focus();
  await expect(restoreButton).toBeHidden();

  await subject.fill('Assunto alterado E2E');
  await expect(restoreButton).toBeVisible();

  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();

  await editor.focus();
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
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

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

  await editor.focus();
  await page.keyboard.type(' Corpo alterado E2E fixo.', { delay: 10 });
  await expect(restoreButton).toBeVisible();
  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();
});
