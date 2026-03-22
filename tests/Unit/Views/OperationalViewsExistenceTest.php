<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views {
    use Modules\Nfse\Tests\TestCase;

    final class OperationalViewsExistenceTest extends TestCase
    {
        public function testOperationalScreensExistInViewsDirectory(): void
        {
            $basePath = dirname(__DIR__, 3) . '/Resources/views';

            self::assertFileExists($basePath . '/dashboard/index.blade.php');
            self::assertFileExists($basePath . '/invoices/index.blade.php');
            self::assertFileExists($basePath . '/invoices/pending.blade.php');
            self::assertFileExists($basePath . '/invoices/show.blade.php');
            self::assertFileExists($basePath . '/settings/readiness.blade.php');
        }

        public function testInvoicesIndexViewKeepsFiltersInPaginationAndOffersClearAction(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString('{{ $receipts->appends(request()->query())->links() }}', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.clear_filters')", $content);
        }

        public function testInvoicesIndexViewOffersReemitActionForCancelledReceipts(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString('route(\'nfse.invoices.reemit\', $receipt->invoice_id)', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.reemit')", $content);
        }

        public function testPendingInvoicesViewKeepsFiltersInPaginationAndOffersClearAction(): void
        {
            $pendingPath = dirname(__DIR__, 3) . '/Resources/views/invoices/pending.blade.php';
            $content = (string) file_get_contents($pendingPath);

            self::assertStringContainsString('{{ $pendingInvoices->appends(request()->query())->links() }}', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.clear_filters')", $content);
        }

        public function testInvoiceShowViewOffersReemitActionForCancelledReceipts(): void
        {
            $showPath = dirname(__DIR__, 3) . '/Resources/views/invoices/show.blade.php';
            $content = (string) file_get_contents($showPath);

            self::assertStringContainsString('route(\'nfse.invoices.reemit\', $invoice)', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.reemit')", $content);
            self::assertStringContainsString("trans('nfse::general.invoices.reemit_confirm')", $content);
        }
    }
}
