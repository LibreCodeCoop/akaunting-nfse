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
            self::assertFileExists($basePath . '/invoices/show.blade.php');
        }

        public function testInvoicesIndexViewKeepsFiltersInPagination(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString('<x-pagination :items="$isPendingStatus ? $pendingInvoices : $receipts" />', $content);
            self::assertStringNotContainsString("trans('nfse::general.invoices.clear_filters')", $content);
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
            self::assertStringContainsString("trans('nfse::general.invoices.cancel_modal_title')", $content);
        }

        public function testInvoicesIndexViewShowsMiniDashboardQuickFiltersAndRowDetails(): void
        {
            $indexPath = dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php';
            $content = (string) file_get_contents($indexPath);

            self::assertStringContainsString("trans('nfse::general.invoices.listing_overview')", $content);
            self::assertStringNotContainsString("trans('nfse::general.invoices.quick_filters')", $content);
            self::assertStringContainsString("trans('nfse::general.invoices.filter_pending')", $content);
            self::assertStringContainsString("trans('general.actions')", $content);
            self::assertStringContainsString('<x-search-string :filters="$searchStringFilters" />', $content);
            self::assertStringContainsString("'key' => 'status'", $content);
            self::assertStringContainsString("'key' => 'data_emissao'", $content);
            self::assertStringContainsString("'type' => 'date'", $content);
            self::assertStringNotContainsString("'key' => 'per_page'", $content);
            self::assertStringContainsString('<x-script folder="common" file="documents" />', $content);
            self::assertStringNotContainsString('id="nfse-status-filter"', $content);
            self::assertStringContainsString('class="bg-white border border-gray-200 rounded-lg overflow-hidden"', $content);
            self::assertStringContainsString('text-xs font-semibold uppercase tracking-wide text-gray-500', $content);
            self::assertStringContainsString('class="group hover:bg-gray-50 transition-colors"', $content);
            self::assertStringContainsString('$invoice->number ?? $invoice->document_number ?? (\'#\' . $invoice->id)', $content);
            self::assertStringContainsString('route(\'nfse.invoices.show\', $invoice)', $content);
            self::assertStringContainsString('route(\'nfse.invoices.show\', $receipt->invoice_id)', $content);
            self::assertStringContainsString('$receipt->invoice?->number ?? $receipt->invoice?->document_number ?? (\'#\' . $receipt->invoice_id)', $content);
            self::assertStringContainsString('class="text-indigo-700 hover:underline"', $content);
            self::assertStringNotContainsString('uppercase">NFS-e</th>', $content);
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
            self::assertStringContainsString("trans('nfse::general.settings.vault_section_title')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.bao_token_local_dev_hint')", $content);
            self::assertStringContainsString('id="toggle-pfx-password"', $content);
            self::assertStringContainsString('id="toggle-bao-token"', $content);
            self::assertStringContainsString('id="toggle-bao-secret-id"', $content);
            self::assertStringContainsString('id="tab-btn-{{ $tabKey }}"', $content);
            self::assertStringContainsString('class="tab-button', $content);
            self::assertStringContainsString('id="tab-panel-vault"', $content);
            self::assertStringContainsString('id="tab-panel-certificate"', $content);
            self::assertStringContainsString('id="tab-panel-fiscal"', $content);
            self::assertStringContainsString('data-eye-open', $content);
            self::assertStringContainsString('data-eye-off', $content);
            self::assertStringContainsString("document.getElementById('pfx_password')", $content);
            self::assertStringContainsString("document.getElementById('bao_token')", $content);
            self::assertStringContainsString("document.getElementById('bao_secret_id')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.show_password')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.hide_password')", $content);
            // Auth mode toggle (token / approle mutually exclusive sections)
            self::assertStringContainsString('id="vault-auth-mode-fieldset"', $content);
            self::assertStringContainsString('id="vault-auth-mode-hint"', $content);
            self::assertStringContainsString("trans('nfse::general.settings.auth_mode_group_label')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.auth_mode_group_hint')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.auth_mode_option_token')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.auth_mode_option_approle')", $content);
            self::assertStringContainsString('name="auth_mode_ui"', $content);
            self::assertStringContainsString('id="auth-mode-token"', $content);
            self::assertStringContainsString('id="auth-mode-approle"', $content);
            self::assertStringContainsString("document.getElementById('vault-token-section')", $content);
            self::assertStringContainsString("document.getElementById('vault-approle-section')", $content);
            self::assertStringContainsString('id="vault-token-section"', $content);
            self::assertStringContainsString('id="vault-approle-section"', $content);
            $fieldsetStart = strpos($content, 'id="vault-auth-mode-fieldset"');
            $fieldsetEnd = strpos($content, '</fieldset>', (int) $fieldsetStart);
            $tokenSection = strpos($content, 'id="vault-token-section"');
            $approleSection = strpos($content, 'id="vault-approle-section"');
            $vaultTitle = strpos($content, "trans('nfse::general.settings.vault_section_title')");
            $vaultReadyNotice = strpos($content, "trans('nfse::general.settings.vault_gate_ready_notice')");
            $certificateStep = strpos($content, "trans('nfse::general.step_certificate')");
            $settingsStep = strpos($content, "trans('nfse::general.step_settings')");
            self::assertIsInt($fieldsetStart);
            self::assertIsInt($fieldsetEnd);
            self::assertIsInt($tokenSection);
            self::assertIsInt($approleSection);
            self::assertIsInt($vaultTitle);
            self::assertIsInt($vaultReadyNotice);
            self::assertIsInt($certificateStep);
            self::assertIsInt($settingsStep);
            self::assertGreaterThan($fieldsetStart, $tokenSection);
            self::assertGreaterThan($fieldsetStart, $approleSection);
            self::assertLessThan($fieldsetEnd, $tokenSection);
            self::assertLessThan($fieldsetEnd, $approleSection);
            self::assertGreaterThan($vaultTitle, $vaultReadyNotice);
            self::assertGreaterThan($vaultTitle, $certificateStep);
            self::assertGreaterThan($vaultTitle, $settingsStep);
            self::assertStringContainsString('id="delete-certificate-form"', $content);
            self::assertStringContainsString("setting('nfse.bao_mount', '/nfse')", $content);
        }

        public function testSettingsViewKeepsServiceSelectionOnlyInServicesTab(): void
        {
            $settingsPath = dirname(__DIR__, 3) . '/Resources/views/settings/edit.blade.php';
            $content = (string) file_get_contents($settingsPath);

            $servicesTabPos = strpos($content, "trans('nfse::general.settings.services.tab_title')");
            $federalTabPos = strpos($content, "trans('nfse::general.settings.federal.tab_title')");
            self::assertIsInt($servicesTabPos);
            self::assertIsInt($federalTabPos);
            self::assertLessThan($federalTabPos, $servicesTabPos);

            self::assertStringContainsString('name="nfse[opcao_simples_nacional]"', $content);
            self::assertStringNotContainsString('name="nfse[item_lista_servico_display]"', $content);
            self::assertStringNotContainsString('name="nfse[item_lista_servico]"', $content);
            self::assertStringNotContainsString('id="lc116_services"', $content);
            self::assertStringNotContainsString("document.getElementById('item_lista_servico_display')", $content);
            self::assertStringContainsString("trans('nfse::general.settings.federal.tab_title')", $content);
            self::assertStringContainsString("route('nfse.settings.federal')", $content);
            self::assertStringContainsString('name="nfse[tributacao_federal_mode]" type="hidden" value="percentage_profile"', $content);
            self::assertStringNotContainsString('value="per_invoice_amounts"', $content);
            self::assertStringContainsString('name="nfse[federal_piscofins_situacao_tributaria]"', $content);
            self::assertStringContainsString('name="nfse[federal_piscofins_tipo_retencao]"', $content);
            self::assertStringContainsString('id="federal-piscofins-preview-note"', $content);
            self::assertStringContainsString("trans('nfse::general.settings.federal.piscofins_preview_note')", $content);
            self::assertStringNotContainsString('name="nfse[federal_piscofins_base_calculo]"', $content);
            self::assertStringNotContainsString('name="nfse[federal_piscofins_valor_pis]"', $content);
            self::assertStringNotContainsString('name="nfse[federal_piscofins_valor_cofins]"', $content);
            self::assertStringContainsString('name="nfse[federal_valor_csll]"', $content);
            self::assertStringContainsString('id="federal-piscofins-panel"', $content);
            self::assertStringContainsString('id="federal-piscofins-situacao"', $content);
            self::assertStringContainsString('id="federal-piscofins-tipo-retencao"', $content);
            self::assertStringContainsString('name="nfse[tributos_fed_p]"', $content);
            self::assertStringContainsString('name="nfse[tributos_mun_sn]"', $content);
            self::assertStringContainsString('id="federal-tributos-profile-p"', $content);
            self::assertStringContainsString('id="federal-tributos-profile-sn"', $content);
            self::assertStringContainsString('id="federal-save-button"', $content);
            self::assertStringContainsString('bg-green-50', $content);
            self::assertStringNotContainsString('id="federal_opcao_simples_status"', $content);
            self::assertStringNotContainsString("trans('nfse::general.settings.federal.current_simples_status')", $content);
            self::assertStringContainsString('data-tax-affix="percent"', $content);
            self::assertStringContainsString('pointer-events-none absolute inset-y-0 right-0', $content);
            self::assertStringNotContainsString('R$ = valor monetario', $content);
        }

        public function testSettingsTranslationsDoNotExposeInternalOpSimpNacFieldNames(): void
        {
            $ptBrPath = dirname(__DIR__, 3) . '/Resources/lang/pt-BR/general.php';
            $enGbPath = dirname(__DIR__, 3) . '/Resources/lang/en-GB/general.php';

            $ptBrContent = (string) file_get_contents($ptBrPath);
            $enGbContent = (string) file_get_contents($enGbPath);

            self::assertStringNotContainsString('opSimpNac', $ptBrContent);
            self::assertStringNotContainsString('opSimpNac', $enGbContent);
            self::assertStringNotContainsString('prontidão operacional', $ptBrContent);
            self::assertStringNotContainsString('operational readiness', $enGbContent);

            self::assertStringContainsString("'opcao_simples_nacional_not_optant' => 'Não optante'", $ptBrContent);
            self::assertStringContainsString("'opcao_simples_nacional_optant' => 'Optante'", $ptBrContent);
            self::assertStringContainsString("'go_to_settings'        => 'Ver configurações'", $ptBrContent);
            self::assertStringContainsString("'go_to_settings'        => 'View settings'", $enGbContent);
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
