// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('closing reports load fiscal data via JSON without embedding taxpayer records in HTML', async ({ page }, info) => {
    await loginToAkaunting(page, info);
    const urls: string[] = [];
    await page.route('**/nfse/closing/data?**', async route => {
        const url = route.request().url();
        urls.push(url);
        const query = new URL(url).searchParams;
        const reportPage = Number(query.get('report_page') || 1);
        const rows = [{
            receipt_id: reportPage, invoice_id: 3, invoice_number: 'INV-E2E-SYNTHETIC',
            nfse_number: '123456', status: 'emitted', gross_service_value: '100.00',
            taxation_group: '1', iss_retention: '1', reconciliation_status: 'matched',
        }];
        await route.fulfill({ status: 200, contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    competence: '2026-10',
                    summary: {
                        gross_service_value: '100.00', iss: { value: '2.00' },
                        documents: { emitted: 1 }, reconciliation_mismatches: 0,
                    },
                    reconciliation: [],
                    rows,
                    commercial_review: {
                        rows: [{
                            invoice_id: 3, invoice_number: 'INV-E2E-SYNTHETIC',
                            issued_at: '2026-10-01', financial_status: 'sent',
                            local_receipts: 1, status: 'authorized_locally',
                        }],
                        meta: { current_page: 1, last_page: 1, per_page: 30, total: 1 },
                    },
                },
                meta: { current_page: reportPage, last_page: 2, per_page: 50, total: 51 },
            }),
        });
    });
    await page.goto('/1/nfse/closing?competence=2026-10', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#closing-gross')).toContainText('100.00');
    await expect(page.locator('#closing-iss')).toContainText('2.00');
    await expect(page.locator('#closing-fiscal-body')).toContainText('INV-E2E-SYNTHETIC');
    await expect(page.locator('#closing-commercial-body')).toContainText('autorizada localmente');
    expect(urls.length).toBeGreaterThan(0);
    expect(urls[0]).toContain('competence=2026-10');
    await page.locator('#closing-next').click();
    await expect(page.locator('#closing-page')).toContainText('Página 2 de 2');
    expect(urls.some(url => url.includes('report_page=2'))).toBe(true);
    await info.attach('fiscal-closing-api-synthetic.png', {
        body: await page.locator('#nfse-closing').screenshot({ animations: 'disabled' }),
        contentType: 'image/png',
    });
});
