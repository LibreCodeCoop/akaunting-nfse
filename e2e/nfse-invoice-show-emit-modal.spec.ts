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

async function setEditorTextAsUser(editor: Locator, text: string): Promise<void> {
  await expect(editor).toBeVisible();
  await editor.fill(text);
  await expect.poll(() => getEditorText(editor)).toContain(text.trim());
}

async function editorSelection(editor: Locator): Promise<{ offset: number; selected: string; line: number }> {
  return editor.evaluate((node: HTMLElement) => {
    const selection = node.ownerDocument.getSelection();
    const anchor = selection?.anchorNode;
    if (!anchor || !node.contains(anchor)) {
      return { offset: -1, selected: '', line: -1 };
    }
    const paragraph = anchor.parentElement?.closest('p') || node.querySelector('p');
    const line = paragraph ? Array.from(node.querySelectorAll('p')).indexOf(paragraph) : -1;
    const range = node.ownerDocument.createRange();
    if (paragraph) {
      range.selectNodeContents(paragraph);
      range.setEnd(anchor, selection?.anchorOffset ?? 0);
    } else {
      range.selectNodeContents(node);
      range.setEnd(anchor, selection?.anchorOffset ?? 0);
    }
    return { offset: range.toString().length, selected: selection?.toString() ?? '', line };
  });
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
  await expect(page.locator('[data-nfse-native-panel="true"]')).toHaveCount(1);
  await expect(page.locator('[data-nfse-native-emit="true"]')).toHaveCount(1);
  await expect(page.getByRole('button', { name: /Emitir NFS-e agora|Emit NFS-e now/i })).toHaveCount(0);

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

  // The Akaunting modal serializes its reactive Form model, not the native DOM select value.
  const descriptionSaveMode = dialog.locator('select[name="nfse_description_save_mode"]');
  await expect(descriptionSaveMode).toHaveValue('none');
  await descriptionSaveMode.selectOption('customer');
  await expect(descriptionSaveMode).toHaveValue('customer');
  await descriptionSaveMode.selectOption('general');

  await dialog.getByText(/E-mail|Email/i).first().click();

  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);
  await expect(dialog.locator('select[name="nfse_email_save_mode"]')).toHaveValue('none');
  await expect(page.locator("input[name='nfse_email_subject']")).toBeVisible();

  await page.locator("input[name='nfse_email_subject']").fill(subjectValue);

  const toRecipients = dialog.locator('#nfse_email_to-entry');
  const ccToggle = dialog.locator('[data-nfse-show-recipient="cc"]');
  const bccToggle = dialog.locator('[data-nfse-show-recipient="bcc"]');
  const ccRecipients = dialog.locator('#nfse_email_cc-entry');
  const bccRecipients = dialog.locator('#nfse_email_bcc-entry');

  await expect(toRecipients).toBeVisible();
  await dialog.getByRole('button', { name: 'Remover customer@company.com' }).click();
  await toRecipients.fill('');
  await toRecipients.pressSequentially('typed@example.test');
  await expect(toRecipients).toHaveValue('typed@example.test');
  await expect(toRecipients).toBeFocused();
  await toRecipients.fill('first@example.test, second@example.test');
  await ccToggle.click();
  await expect(ccToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(ccToggle).toBeHidden();
  await expect(ccRecipients).toBeVisible();
  await expect(ccRecipients).toBeFocused();
  await ccRecipients.fill('visible@example.test');
  await bccToggle.click();
  await expect(bccToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(bccToggle).toBeHidden();
  await expect(bccRecipients).toBeVisible();
  await bccRecipients.fill('hidden@example.test, private@example.test');

  // Removing a chip keeps unrelated addresses and recipient roles intact.
  await toRecipients.press('Tab');
  await expect(dialog.locator('[data-nfse-recipient-chipbox="nfse_email_to"] [data-nfse-recipient-chip]')).toHaveCount(2);
  await dialog.getByRole('button', { name: 'Remover second@example.test' }).click();
  await expect(dialog.locator('#nfse_email_to')).toHaveValue('first@example.test');
  await expect(dialog.locator('#nfse_email_cc')).toHaveValue('visible@example.test');
  await toRecipients.fill('second@example.test');
  await toRecipients.press('Enter');
  await testInfo.attach('nfse-email-recipient-fields', {
    body: await dialog.screenshot(),
    contentType: 'image/png',
  });

  await dialog.locator('select[name="nfse_email_save_mode"]').selectOption('customer');
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
  await expect(dialog.locator('#nfse_email_to')).toHaveValue('first@example.test, second@example.test');
  await expect(dialog.locator('#nfse_email_cc')).toHaveValue('visible@example.test');
  await expect(dialog.locator('#nfse_email_bcc')).toHaveValue('hidden@example.test, private@example.test');
  await expect(dialog.locator('select[name="nfse_email_save_mode"]')).toHaveValue('customer');
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
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_to')).toBe('first@example.test, second@example.test');
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_cc')).toBe('visible@example.test');
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_bcc')).toBe('hidden@example.test, private@example.test');
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_description_save_mode')).toBe('general');

  // Boolean fields can be normalized by the generic modal serializer; ensure they are present.
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_send_email')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_save_mode')).toBe('customer');
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_copy_to_self')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_invoice_pdf')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_danfse')).not.toBeNull();
  expect(extractPayloadValue(capturedEmitPayload, 'nfse_email_attach_xml')).not.toBeNull();

  await expect(page).toHaveURL(new RegExp(`/1/sales/invoices/${selectedInvoiceId}$`));
});


test('unchanged template-save option keeps one To and three Cc valid on fiscal submit', async ({ page }, testInfo) => {
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';
  expect(invoiceId).toMatch(/^\d+$/);
  await loginToAkaunting(page, testInfo);
  await page.goto(`/1/sales/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-nfse-native-emit="true"]')).toBeVisible();
  await page.locator('[data-nfse-native-emit="true"]').click();
  const dialog = page.locator('[role="dialog"]').last();
  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

  await expect(dialog.locator('select[name="nfse_email_save_mode"]')).toHaveValue('none');
  await expect(dialog.locator('[data-nfse-recipient-chipbox="nfse_email_to"] [data-nfse-recipient-chip]')).toHaveCount(1);
  await dialog.locator('[data-nfse-show-recipient="cc"]').click();
  const cc = dialog.locator('#nfse_email_cc-entry');
  for (const recipient of ['one@example.test', 'two@example.test', 'three@example.test']) {
    await cc.fill(recipient);
    if (recipient !== 'three@example.test') {
      await cc.press('Enter');
    }
  }
  // Clicking Emitir must commit the final address even without Enter.
  await expect(cc).toHaveValue('three@example.test');

  let payload = '';
  await page.route(new RegExp(`/[0-9]+/nfse/invoices/${invoiceId}/emit$`), async route => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    payload = route.request().postData() ?? '';
    // Never transmit a fiscal issuance from a browser regression test.
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      success: true, error: false, redirect: `/1/sales/invoices/${invoiceId}`,
    }) });
  });

  await dialog.getByRole('button', { name: /Emitir NFS-e agora|Emit NFS-e now/i }).click();
  await expect.poll(() => payload).not.toBe('');
  expect(extractPayloadValue(payload, 'nfse_email_to')).toBe('customer@company.com');
  expect(extractPayloadValue(payload, 'nfse_email_cc')).toBe('one@example.test, two@example.test, three@example.test');
  expect(extractPayloadValue(payload, 'nfse_email_save_mode')).toBe('none');
});

test('legacy NFS-e invoice list opens native invoice composer without a parallel mail form', async ({ page }, testInfo) => {
  const readyId = process.env.NFSE_E2E_BULK_READY_INVOICE_ID ?? '';
  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';

  expect(readyId).toMatch(/^\d+$/);
  expect(invoiceId).toMatch(/^\d+$/);
  await loginToAkaunting(page, testInfo);

  // This route is a compatibility redirect; the former NFS-e list view is no longer reachable.
  await page.goto('/1/nfse/invoices?status=pending', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/1\/sales\/invoices(?:\?.*)?$/);
  await expect(page.locator('[data-bulk-action="' + readyId + '"]')).toBeVisible();

  // Bulk readiness and individual issuance readiness are different.
  // The pending invoice is the proven deterministic fixture for this modal.
  await page.goto('/1/sales/invoices/' + invoiceId, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#nfse-native-fiscal-panel')).toBeVisible();
  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();

  const dialog = page.locator('[role="dialog"]').last();
  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

  const to = dialog.locator('#nfse_email_to-entry');
  const cc = dialog.locator('#nfse_email_cc-entry');
  const bcc = dialog.locator('#nfse_email_bcc-entry');

  await expect(to).toBeVisible();
  await dialog.getByRole('button', { name: 'Remover customer@company.com' }).click();
  await to.fill('primary@example.test, second@example.test');

  const ccToggle = dialog.locator('[data-nfse-show-recipient="cc"]');
  await ccToggle.click();
  await expect(ccToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(ccToggle).toBeHidden();
  await expect(cc).toBeVisible();
  await cc.fill('visible@example.test');

  const bccToggle = dialog.locator('[data-nfse-show-recipient="bcc"]');
  await bccToggle.click();
  await expect(bccToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(bccToggle).toBeHidden();
  await expect(bcc).toBeVisible();
  await bcc.fill('hidden@example.test');

  await expect(dialog.locator('#nfse_email_to')).toHaveValue('primary@example.test, second@example.test');
  await expect(dialog.locator('#nfse_email_cc')).toHaveValue('visible@example.test');
  await expect(dialog.locator('#nfse_email_bcc')).toHaveValue('hidden@example.test');
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
  const editor = dialog.locator('.ck-editor__editable[contenteditable="true"]').first();

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();

  const typedText = 'Teste E2E: digitar no body deve manter a aba Email visivel.';

  await setEditorTextAsUser(editor, typedText);

  await expect(emailPane).toBeVisible();
  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();
  await expect.poll(async () => getEditorText(editor)).toContain(typedText);
  await expect.poll(async () => {
    return editor.evaluate((node) => node.contains(document.activeElement));
  }).toBeTruthy();

  // Pasting into the native subject (not the rich editor) must not collapse the pane.
  await subject.fill('Antes do Ctrl+V');
  await subject.focus();
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.evaluate(async () => navigator.clipboard.writeText(' - mensagem colada'));
  await page.keyboard.press('ControlOrMeta+v');
  await expect(subject).toHaveValue('Antes do Ctrl+V - mensagem colada');
  await expect(emailPane).toBeVisible();
  await expect(dialog.getByRole('tab', { name: /E-mail|Email/i })).toHaveAttribute('aria-selected', 'true');

  // Regression: Ctrl used to collapse the Email tab and, after changing tabs,
  // could leave the entire pane hidden until the modal was reopened.
  await page.keyboard.down('Control');
  await expect(emailPane).toBeVisible();
  await expect(editor).toBeVisible();
  await page.keyboard.up('Control');
  await expect(emailPane).toBeVisible();

  await dialog.getByText(/Geral|General/i).first().click();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await editor.focus();
  await page.keyboard.down('Control');
  await expect(emailPane).toBeVisible();
  await page.keyboard.up('Control');
  await expect(editor).toBeVisible();

  // Real CKEditor keyboard behavior: Home/End, arrows, and selected text.
  await setEditorTextAsUser(editor, 'abcd');
  await editor.focus();
  await page.keyboard.press('End');
  await expect.poll(async () => (await editorSelection(editor)).offset).toBe(4);
  await page.keyboard.press('Home');
  await expect.poll(async () => (await editorSelection(editor)).offset).toBe(0);
  await page.keyboard.press('End');
  await page.keyboard.press('ArrowLeft');
  await expect.poll(async () => (await editorSelection(editor)).offset).toBe(3);
  await page.keyboard.press('ArrowRight');
  await expect.poll(async () => (await editorSelection(editor)).offset).toBe(4);
  await page.keyboard.press('Shift+ArrowLeft');
  await expect.poll(async () => (await editorSelection(editor)).selected).toBe('d');
  await page.keyboard.press('ArrowLeft');
  await expect.poll(async () => (await editorSelection(editor)).selected).toBe('');
  await expect.poll(async () => (await editorSelection(editor)).offset).toBe(3);

  // Pressing Enter creates a second paragraph; vertical navigation remains native.
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('efgh');
  await expect.poll(async () => (await editorSelection(editor)).line).toBe(1);
  await page.keyboard.press('ArrowUp');
  await expect.poll(async () => (await editorSelection(editor)).line).toBe(0);
  await page.keyboard.press('ArrowDown');
  await expect.poll(async () => (await editorSelection(editor)).line).toBe(1);
  await expect.poll(() => dialog.locator('[name="nfse_email_body"]').inputValue()).toContain('efgh');

  // Undo works in the editor and updates the form payload.
  await page.keyboard.press('ControlOrMeta+z');
  await expect.poll(() => getEditorText(editor)).not.toContain('efgh');
  await expect(emailPane).toBeVisible();
  await expect(editor).toBeFocused();
  await expect.poll(() => getEditorText(editor)).toContain('abcd');
});

test('email body and customer-save controls have matching alignment without enabling delivery', async ({ page }, testInfo) => {
  test.setTimeout(180_000);

  const invoiceId = process.env.NFSE_E2E_PENDING_INVOICE_ID ?? '';
  expect(invoiceId).toMatch(/^\d+$/);

  await loginToAkaunting(page, testInfo);
  await page.goto('/1/sales/invoices/' + invoiceId, { waitUntil: 'domcontentloaded' });
  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();

  const dialog = page.locator('[role="dialog"]').last();
  await dialog.getByRole('tab', { name: /E-mail|Email/i }).click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', false);

  const emailFields = dialog.locator('#nfse-email-fields');
  const subject = dialog.locator('input[name="nfse_email_subject"]');
  const bodyGroup = dialog.locator('[data-nfse-email-body-group]');
  const editor = dialog.locator('.ck-editor__editable[contenteditable="true"]').first();
  const saveControls = dialog.locator('[data-nfse-email-save-controls]');
  const saveMode = dialog.locator('select[name="nfse_email_save_mode"]');
  const saveButton = dialog.locator('[data-nfse-save-customer-email]');
  const recipients = dialog.locator('#nfse-email-recipient-fields');

  await expect(emailFields).toBeVisible();
  await expect(subject).toBeVisible();
  await expect(bodyGroup).toBeVisible();
  await expect(editor).toBeVisible();
  await expect(saveMode).toBeVisible();
  await expect(saveButton).toBeVisible();
  await expect(recipients).toBeHidden();
  await expect(dialog.locator('#nfse-tab-nav-attachments')).toBeHidden();

  const aligned = async () => {
    const [subjectBounds, bodyBounds, saveBounds, selectBounds, buttonBounds] = await Promise.all([
      subject.boundingBox(), bodyGroup.boundingBox(), saveControls.boundingBox(),
      saveMode.boundingBox(), saveButton.boundingBox(),
    ]);
    expect(subjectBounds).not.toBeNull();
    expect(bodyBounds).not.toBeNull();
    expect(saveBounds).not.toBeNull();
    expect(selectBounds).not.toBeNull();
    expect(buttonBounds).not.toBeNull();
    const left = subjectBounds!.x;
    const right = left + subjectBounds!.width;
    for (const bounds of [bodyBounds!, saveBounds!, selectBounds!]) {
      expect(Math.abs(bounds.x - left)).toBeLessThanOrEqual(2);
      expect(Math.abs(bounds.x + bounds.width - right)).toBeLessThanOrEqual(2);
    }
    expect(Math.abs(buttonBounds!.x - left)).toBeLessThanOrEqual(2);
  };

  await aligned();

  // Akaunting's app.css gives the editor 9px of inline padding (!important),
  // while native inputs use 12px. Both composers need the same text inset.
  const editorInset = await editor.evaluate((node) => parseFloat(getComputedStyle(node).paddingInlineStart));
  const nativeInset = await subject.evaluate((node) => parseFloat(getComputedStyle(node).paddingInlineStart));
  expect(Math.abs(editorInset - nativeInset)).toBeLessThanOrEqual(1);

  await page.setViewportSize({ width: 390, height: 844 });
  await aligned();
  const hasHorizontalOverflow = await emailFields.evaluate((node) => node.scrollWidth > node.clientWidth + 2);
  expect(hasHorizontalOverflow).toBeFalsy();

  // The model can be saved independently of the fiscal submit operation.
  let saves = 0;
  await page.route('**/nfse/invoices/*/email-preferences', async (route) => {
    if (route.request().method() === 'POST') {
      saves += 1;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, scope: 'customer' }) });
      return;
    }
    await route.continue();
  });
  await saveMode.selectOption('customer');
  await expect(dialog.locator('#nfse-tab-pane-email')).toBeVisible();
  await saveButton.click();
  await expect(dialog.locator('[data-nfse-customer-email-save-status]')).toContainText(/saved|salvo/i);
  await expect(dialog.locator('#nfse-tab-pane-email')).toBeVisible();
  await expect(dialog.getByRole('tab', { name: /E-mail|Email/i })).toHaveAttribute('aria-selected', 'true');
  await expect(subject).toBeVisible();
  await expect(editor).toBeVisible();
  expect(saves).toBe(1);
  await expect(page.locator('#nfse_send_email_toggle')).not.toBeChecked();
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
  const editor = dialog.locator('.ck-editor__editable[contenteditable="true"]').first();
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

  await setEditorTextAsUser(editor, `${initialBodyText} Corpo alterado E2E.`);
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

  expect(await openEmitActionFromInvoiceShow(page)).toBeTruthy();

  const dialog = page.locator('[role="dialog"]').last();

  await expect(dialog).toBeVisible();
  await dialog.getByText(/E-mail|Email/i).first().click();
  await setVisibleSwitch(page, 'nfse_send_email_toggle', true);

  const subject = dialog.locator("input[name='nfse_email_subject']");
  const editor = dialog.locator('.ck-editor__editable[contenteditable="true"]').first();
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

  await setEditorTextAsUser(editor, `${initialBodyText} Corpo alterado E2E fixo.`);
  await expect(restoreButton).toBeVisible();
  await clickRestoreButton(restoreButton);
  await expect(subject).toHaveValue(initialSubject);
  await expect.poll(async () => getEditorText(editor)).toBe(initialBodyText);
  await expect(restoreButton).toBeHidden();
});
