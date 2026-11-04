// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test';
import { loginToAkaunting } from './support/auth';

test.use({ serviceWorkers: 'block' });

test('receipt list uses JSON and preserves scoped XML/DANFSe links', async ({ page }, info) => {
    await loginToAkaunting(page, info);
    const requests: string[] = [];
    await page.route('**/nfse/ledger/data?**', async route => {
        const url = route.request().url();
        requests.push(url);
        const pageNumber = Number(new URL(url).searchParams.get('page') || 1);
        await route.fulfill({ status: 200, contentType: 'application/json',
            body: JSON.stringify({
                data: [{
                    id: pageNumber === 1 ? 41 : 42,
                    invoice_id: 3, invoice_number: 'INV-SYNTHETIC-1',
                    nfse_number: pageNumber === 1 ? 'NFSE-E2E-00041' : 'NFSE-E2E-00042',
                    customer: 'Synthetic Accounting Customer',
                    issue_date: '2026-10-01', status: 'emitted',
                    replaces_receipt_id: null, can_download: true,
                }],
                meta: { current_page: pageNumber, last_page: 2, per_page: 25, total: 26 },
            }),
        });
    });

    await page.goto('/1/nfse/ledger?status=emitted', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#nfse-ledger-rows')).toContainText('NFSE-E2E-00041');
    await expect(page.locator('#nfse-ledger-rows')).toContainText('Synthetic Accounting Customer');
    const row = page.locator('#nfse-ledger-rows tr').first();
    await expect(row.getByRole('link', { name: 'XML' }))
        .toHaveAttribute('href', /\/ledger\/41\/artifacts\/xml|\/ledger\/41\/xml/);
    await expect(row.getByRole('link', { name: 'DANFSe' }))
        .toHaveAttribute('href', /41.*danfse/);
    expect(requests[0]).toContain('status=emitted');

    await page.locator('#nfse-ledger-next').click();
    await expect(page.locator('#nfse-ledger-rows')).toContainText('NFSE-E2E-00042');
    await expect(page.locator('#nfse-ledger-page')).toContainText('Página 2 de 2');
    expect(requests.some(url => url.includes('page=2'))).toBe(true);
    await info.attach('nfse-ledger-synthetic.png', {
        body: await page.locator('#nfse-ledger-browser').screenshot({ animations: 'disabled' }),
        contentType: 'image/png',
    });
});
