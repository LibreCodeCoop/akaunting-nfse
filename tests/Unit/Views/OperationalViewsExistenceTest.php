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

        public function testInvoicesIndexViewOffersCancelActionForEmittedReceipts(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString('route(\'nfse.invoices.cancel\', $receipt->invoice_id)', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.cancel')", $content);
            self::assertStringContainsString("trans('nfse::general.invoices.cancel_confirm')", $content);
        }

        public function testInvoicesIndexViewShowsMiniDashboardQuickFiltersAndRowDetails(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString("trans('nfse::general.invoices.listing_overview')", $content);
            self::assertStringContainsString("trans('nfse::general.invoices.quick_filters')", $content);
            self::assertStringContainsString('<details class="group">', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.more_details')", $content);
        }

        public function testPendingInvoicesViewKeepsFiltersInPaginationAndOffersClearAction(): void
        {
            $pendingPath = dirname(__DIR__, 3) . '/Resources/views/invoices/pending.blade.php';
            $content = (string) file_get_contents($pendingPath);

            self::assertStringContainsString('{{ $pendingInvoices->appends(request()->query())->links() }}', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.clear_filters')", $content);
        }

        public function testPendingViewListsSpecificMissingChecklistItemsWhenNotReady(): void
        {
            $pendingPath = dirname(__DIR__, 3) . '/Resources/views/invoices/pending.blade.php';
            $content = (string) file_get_contents($pendingPath);

            // The view must iterate \$checklist to surface per-item labels rather than a generic banner.
            self::assertStringContainsString('$checklist', $content);
            self::assertStringContainsString("nfse::general.readiness.checks.", $content);
        }

        public function testSettingsViewShowsVaultStatusAndSensitiveFieldClearControls(): void
        {
            $settingsPath = dirname(__DIR__, 3) . '/Resources/views/settings/edit.blade.php';
            $content = (string) file_get_contents($settingsPath);

            self::assertStringContainsString('id="vault-status-token"', $content);
            self::assertStringContainsString('id="vault-status-auth-mode"', $content);
            self::assertStringContainsString('name="nfse[clear_bao_token]"', $content);
            self::assertStringContainsString('name="nfse[clear_bao_secret_id]"', $content);
            self::assertStringContainsString("trans('nfse::general.settings.sensitive_fields_behavior_hint')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.vault_gate_locked_notice')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.vault_gate_ready_notice')", $content);
            // Auth mode toggle (token / approle mutually exclusive sections)
            self::assertStringContainsString('name="auth_mode_ui"', $content);
            self::assertStringContainsString('id="auth-mode-token"', $content);
            self::assertStringContainsString('id="auth-mode-approle"', $content);
            self::assertStringContainsString('id="vault-token-section"', $content);
            self::assertStringContainsString('id="vault-approle-section"', $content);
            self::assertStringNotContainsString('id="delete-certificate-form"', $content);
        }

        public function testPendingInvoicesViewShowsCompactSummaryAndCustomFilterInput(): void
        {
            $pendingPath = dirname(__DIR__, 3) . '/Resources/views/invoices/pending.blade.php';
            $content = (string) file_get_contents($pendingPath);

            self::assertStringContainsString("trans('nfse::general.invoices.pending_summary')", $content);
            self::assertStringContainsString('id="pending-search-field"', $content);
            self::assertStringContainsString("trans('nfse::general.invoices.search_in_field')", $content);
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
