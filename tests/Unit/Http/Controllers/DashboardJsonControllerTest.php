<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Nfse\Http\Controllers\DashboardJsonController;
use Modules\Nfse\Tests\TestCase;

final class DashboardJsonControllerTest extends TestCase
{
    public function testDashboardHtmlPreservesSafeFiltersButNeverQueriesPrivateFiscalData(): void
    {
        $controller = new class () extends DashboardJsonController {
            protected function dashboardStats(): array
            {
                throw new \LogicException('Rendering the HTML shell must not load private totals.');
            }

            protected function dashboardRecentReceipts(): array
            {
                throw new \LogicException('Rendering the HTML shell must not load fiscal receipts.');
            }
        };

        $response = $controller->dashboard(new Request([
            'search' => '  Empresa Ficticia  ',
            'status' => 'emitted',
            'sort' => 'amount',
            'direction' => 'asc',
        ]));

        self::assertSame('nfse::dashboard.json', $response->name);
        self::assertSame('Empresa Ficticia', $response->data['filters']['search']);
        self::assertSame('emitted', $response->data['filters']['status']);
        self::assertSame('amount', $response->data['filters']['sort']);
        self::assertSame('asc', $response->data['filters']['direction']);
        self::assertArrayNotHasKey('stats', $response->data);
        self::assertArrayNotHasKey('recentReceipts', $response->data);
    }

    public function testJsonDashboardPreservesReceiptAmountAndInvoiceLink(): void
    {
        $controller = new class () extends DashboardJsonController {
            protected function dashboardStats(): array
            {
                return ['total' => 1, 'emitted' => 1, 'cancelled' => 0, 'sandbox_mode' => true];
            }

            protected function dashboardRecentReceipts(): array
            {
                return [(object) [
                    'invoice_id' => 37,
                    'nfse_number' => 'SYNTHETIC-37',
                    'customer_name' => '<script>fictitious</script>',
                    'data_emissao' => '2026-11-12',
                    'amount' => 120.5,
                    'currency_code' => 'BRL',
                    'status' => 'emitted',
                ]];
            }

            protected function receiptAmountLabel(object $receipt): string
            {
                \PHPUnit\Framework\Assert::assertSame(120.5, $receipt->amount);

                return 'R$ 120,50';
            }
        };

        $response = $controller->dashboardData(new Request([
            'sort' => 'amount', 'direction' => 'asc',
        ]))->getData(true);

        self::assertSame(1, $response['data']['stats']['total']);
        self::assertSame('SYNTHETIC-37', $response['data']['recent_receipts'][0]['nfse_number']);
        self::assertSame('R$ 120,50', $response['data']['recent_receipts'][0]['amount_label']);
        self::assertSame('<script>fictitious</script>', $response['data']['recent_receipts'][0]['customer_name']);
        self::assertStringContainsString('/37', $response['data']['recent_receipts'][0]['invoice_url']);
    }
}
