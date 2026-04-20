<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers\Modals;

use Modules\Nfse\Tests\TestCase;

final class InvoiceEmailsTest extends TestCase
{
    private function controllerContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Http/Controllers/Modals/InvoiceEmails.php'
        );
    }

    private function viewContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Resources/views/modals/invoices/email.blade.php'
        );
    }

    private function issueViewContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Resources/views/modals/invoices/issue.blade.php'
        );
    }

    private function cancelViewContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Resources/views/modals/invoices/cancel.blade.php'
        );
    }

    private function issueSwitchPartialContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Resources/views/modals/invoices/partials/switch.blade.php'
        );
    }

    private function routesContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Routes/admin.php'
        );
    }

    private function serviceProviderContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 5) . '/Providers/Main.php'
        );
    }

    // --- Controller structure ---

    public function testControllerExtendsAkauntingBaseController(): void
    {
        self::assertStringContainsString(
            "use App\\Abstracts\\Http\\Controller;",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            'extends Controller',
            $this->controllerContent()
        );
    }

    public function testControllerUsesEmailsTrait(): void
    {
        self::assertStringContainsString(
            "use App\\Traits\\Emails;",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            'use Emails;',
            $this->controllerContent()
        );
    }

    public function testControllerCreateMethodReturnsJsonResponse(): void
    {
        self::assertStringContainsString(
            'Illuminate\\Http\\JsonResponse',
            $this->controllerContent()
        );
        self::assertStringContainsString(
            'public function create(',
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "response()->json(",
            $this->controllerContent()
        );
    }

    public function testControllerCreatePassesContactsToView(): void
    {
        self::assertStringContainsString(
            "invoice->contact->withPersons()",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "'contacts'",
            $this->controllerContent()
        );
    }

    public function testControllerCreateBuildsNfseIssuedNotificationForTemplateContent(): void
    {
        self::assertStringContainsString(
            'NfseIssued',
            $this->controllerContent()
        );
    }

    public function testControllerMirrorsCoreSalesInvoicePermissions(): void
    {
        self::assertStringContainsString(
            "permission:create-sales-invoices",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "permission:read-sales-invoices",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "permission:update-sales-invoices",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "permission:delete-sales-invoices",
            $this->controllerContent()
        );
    }

    public function testControllerCreateUsesDecisionFlowForIssueOrEmailModal(): void
    {
        self::assertStringContainsString(
            'isEmitted',
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "nfse::modals.invoices.cancel",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "nfse::modals.invoices.issue",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            'issuePreviewData',
            $this->controllerContent()
        );
    }

    public function testControllerCreateUsesCancelTitleAndConfirmButtonForEmittedReceipts(): void
    {
        self::assertStringContainsString(
            "trans('nfse::general.invoices.cancel_modal_title')",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "trans('nfse::general.invoices.cancel_modal_submit')",
            $this->controllerContent()
        );
    }

    public function testControllerCreatePassesStoreRouteToView(): void
    {
        self::assertStringContainsString(
            "nfse.modals.invoices.emails.store",
            $this->controllerContent()
        );
    }

    public function testControllerStoreMethodDispatchesEmail(): void
    {
        self::assertStringContainsString(
            'public function store(',
            $this->controllerContent()
        );
        self::assertStringContainsString(
            'sendEmail(',
            $this->controllerContent()
        );
    }

    public function testControllerStoreHandlesCopyToMyselfBcc(): void
    {
        self::assertStringContainsString(
            "user_email",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "user()->email",
            $this->controllerContent()
        );
        self::assertStringContainsString(
            "'bcc'",
            $this->controllerContent()
        );
    }

    // --- View structure ---

    public function testViewContainsToFieldPrefilledFromContactEmail(): void
    {
        $view = $this->viewContent();
        self::assertStringContainsString('name="to"', $view);
        self::assertStringContainsString('invoice->contact', $view);
        self::assertStringContainsString('email', $view);
    }

    public function testViewContainsCopyToMyselfCheckbox(): void
    {
        $view = $this->viewContent();
        self::assertStringContainsString('name="user_email"', $view);
        self::assertStringContainsString("user()->email", $view);
    }

    public function testViewContainsDanfseAttachmentField(): void
    {
        $view = $this->viewContent();
        self::assertStringContainsString('nfse_attach_danfse', $view);
    }

    public function testViewContainsXmlAttachmentField(): void
    {
        $view = $this->viewContent();
        self::assertStringContainsString('nfse_attach_xml', $view);
    }

    public function testViewUsesTabsForGeneralAndAttachments(): void
    {
        $view = $this->viewContent();
        self::assertStringContainsString('x-tabs', $view);
        self::assertStringContainsString('id="general"', $view);
        self::assertStringContainsString('id="attachments"', $view);
    }

    public function testIssueViewPostsToEmitOrReemitFlowWithCustomEmailFields(): void
    {
        $view = $this->issueViewContent();

        self::assertStringContainsString('nfse_discriminacao_custom', $view);
        self::assertStringContainsString('nfse_send_email', $view);
        self::assertStringContainsString('nfse_email_to', $view);
        self::assertStringContainsString('nfse_email_subject', $view);
        self::assertStringContainsString('nfse_email_body', $view);
        self::assertStringContainsString('nfse_email_attach_invoice_pdf', $view);
        self::assertStringContainsString('nfse_email_attach_danfse', $view);
        self::assertStringContainsString('nfse_email_attach_xml', $view);
        self::assertStringContainsString('nfse_email_copy_to_self', $view);
        self::assertStringContainsString('nfse_email_save_default', $view);
        self::assertStringContainsString('x-form.group.contact', $view);
        self::assertStringContainsString("'key' => 'email'", $view);
        self::assertStringContainsString("'value' => 'email'", $view);
    }

    public function testCancelViewPostsToCancelRouteWithStructuredCancellationFields(): void
    {
        $view = $this->cancelViewContent();

        self::assertStringContainsString('method="DELETE"', $view);
        self::assertStringContainsString('cancel_reason', $view);
        self::assertStringContainsString('cancel_justification', $view);
        self::assertStringContainsString("trans('nfse::general.invoices.cancel_modal_reason')", $view);
        self::assertStringContainsString("trans('nfse::general.invoices.cancel_modal_justification')", $view);
        self::assertStringContainsString('nfse::general.invoices.cancel_reason_options', $view);
        self::assertStringContainsString('redirect_after_cancel', $view);
    }

    public function testControllerCreateClassifiesCancelRedirectTargetFromQueryOrReferer(): void
    {
        $content = $this->controllerContent();

        self::assertStringContainsString('cancelRedirectTarget', $content);
        self::assertStringContainsString('$request ??= request();', $content);
        self::assertStringContainsString("request->query('redirect_after_cancel', '')", $content);
        self::assertStringContainsString("in_array(", $content);
        self::assertStringContainsString("request->header('referer', '')", $content);
        self::assertStringContainsString("return 'invoice_show';", $content);
        self::assertStringContainsString("return 'nfse_show';", $content);
        self::assertStringContainsString("return 'nfse_index';", $content);
    }

    public function testInvoiceControllerNormalizesRecipientForEmitEmailModalPayloads(): void
    {
        $invoiceController = (string) file_get_contents(
            dirname(__DIR__, 5) . '/Http/Controllers/InvoiceController.php'
        );

        self::assertStringContainsString('normalizePostEmitRecipient', $invoiceController);
        self::assertStringContainsString("request->input('nfse_email_to')", $invoiceController);
    }

    public function testIssueViewUsesTabbedWizardLikeLayout(): void
    {
        $view = $this->issueViewContent();

        // Plain-JS tabs (no Alpine.js x-tabs) so they work in AJAX-loaded modal context.
        self::assertStringContainsString('data-nfse-tabs', $view);
        self::assertStringContainsString('id="nfse-tab-nav-list"', $view);
        self::assertStringContainsString('class="grid w-full auto-rows-max', $view);
        self::assertStringContainsString('data-nfse-tab-nav="nfse-tab-pane-issuance"', $view);
        self::assertStringContainsString('data-nfse-tab-nav="nfse-tab-pane-email"', $view);
        self::assertStringContainsString('data-nfse-tab-nav="nfse-tab-pane-attachments"', $view);
        self::assertStringContainsString('id="nfse-tab-pane-issuance"', $view);
        self::assertStringContainsString('id="nfse-tab-pane-email"', $view);
        self::assertStringContainsString('id="nfse-tab-pane-attachments"', $view);
        self::assertStringContainsString('data-nfse-tab-pane', $view);
    }

    public function testIssueViewTabPanesAreHiddenByDefaultExceptIssuance(): void
    {
        $view = $this->issueViewContent();

        // Email and attachments panes must start hidden so only the issuance pane is
        // visible when the modal first opens (no Alpine.js x-show available in AJAX context).
        self::assertStringContainsString(
            'id="nfse-tab-pane-email" data-nfse-tab-pane="true" style="display:none;"',
            $view
        );
        self::assertStringContainsString(
            'id="nfse-tab-pane-attachments" data-nfse-tab-pane="true" style="display:none;"',
            $view
        );
    }

    public function testIssueViewDoesNotUseAlpineJsXShowOrXOnClickOnTabs(): void
    {
        $view = $this->issueViewContent();

        // x-show and x-on:click from x-tabs are not initialized when HTML is injected
        // via AJAX into the Akaunting modal, so they must not be used for tab switching.
        self::assertStringNotContainsString('x-show="active', $view);
        self::assertStringNotContainsString("x-on:click=\"active =", $view);
    }

    public function testIssueViewSendEmailToggleReferencesNewTabNavIds(): void
    {
        $view = $this->issueViewContent();

        // The send-email toggle extraOnChange must target the new Alpine-free tab IDs.
        self::assertStringContainsString('nfse-tab-nav-list', $view);
        self::assertStringContainsString('nfse-tab-nav-attachments', $view);
        self::assertStringContainsString('nfse-tab-nav-email', $view);
        self::assertStringContainsString("navList.classList.toggle('grid-cols-3',cb.checked)", $view);
        self::assertStringContainsString("navList.classList.toggle('grid-cols-2',!cb.checked)", $view);
        // Must NOT reference the old x-tabs duplicate-id pattern.
        self::assertStringNotContainsString("querySelectorAll('#tab-attachments')", $view);
        self::assertStringNotContainsString("querySelector('#tab-email[data-tabs=email]')", $view);
    }

    public function testIssueViewUsesSwitchTogglesInsteadOfPlainCheckboxRows(): void
    {
        $view = $this->issueViewContent();
        $partial = $this->issueSwitchPartialContent();

        self::assertStringContainsString("@include('nfse::modals.invoices.partials.switch'", $view);
        self::assertStringContainsString("'name' => 'nfse_send_email'", $view);
        self::assertStringContainsString('data-toggle="track"', $partial);
        self::assertStringContainsString('data-toggle="thumb"', $partial);
        self::assertStringContainsString('data-nfse-switch', $partial);
        self::assertStringContainsString('class="peer sr-only"', $partial);
        self::assertStringContainsString("type=\"checkbox\"", $partial);
        self::assertStringContainsString('name="{{ $switchName }}"', $partial);
        self::assertStringContainsString("value=\"1\"", $partial);
        self::assertStringContainsString("track.style.backgroundColor = cb.checked ? '#5e9f4d' : '#dbe8d4'", $partial);
        self::assertStringContainsString("thumb.style.left = cb.checked ? '1.5rem' : '0.25rem'", $partial);
        // Hidden input must carry the initial state and always submit (the value-sync strategy).
        self::assertStringContainsString("name=\"{{ \$switchName }}\" value=\"{{ \$switchChecked ? '1' : '0' }}\"", $partial);
    }

    // --- Routes ---

    public function testCreateRouteRegisteredForNfseEmailModal(): void
    {
        // Route::admin('nfse', ...) prepends 'nfse.' at runtime; file has the partial name.
        self::assertStringContainsString(
            "modals.invoices.emails.create",
            $this->routesContent()
        );
    }

    public function testStoreRouteRegisteredForNfseEmailModal(): void
    {
        // Route::admin('nfse', ...) prepends 'nfse.' at runtime; file has the partial name.
        self::assertStringContainsString(
            "modals.invoices.emails.store",
            $this->routesContent()
        );
        // Full runtime name is referenced in the controller.
        self::assertStringContainsString(
            "nfse.modals.invoices.emails.store",
            $this->controllerContent()
        );
    }

    // --- ServiceProvider safety ---

    public function testServiceProviderDoesNotOverrideCoreInvoiceEmailRouteGlobally(): void
    {
        self::assertStringNotContainsString(
            "type.document.invoice.route.emails.create",
            $this->serviceProviderContent()
        );
        self::assertStringNotContainsString(
            "nfse.modals.invoices.emails.create",
            $this->serviceProviderContent()
        );
    }
}
