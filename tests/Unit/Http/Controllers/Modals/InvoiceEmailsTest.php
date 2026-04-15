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

    // --- Routes ---

    public function testCreateRouteRegisteredForNfseEmailModal(): void
    {
        // Route::admin('nfse', ...) prepends 'nfse.' at runtime; file has the partial name.
        self::assertStringContainsString(
            "modals.invoices.emails.create",
            $this->routesContent()
        );
        // Full runtime name 'nfse.modals.invoices.emails.create' is referenced in controller + SP.
        self::assertStringContainsString(
            "nfse.modals.invoices.emails.create",
            $this->controllerContent() . $this->serviceProviderContent()
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

    // --- ServiceProvider config override ---

    public function testServiceProviderOverridesEmailRouteInTypeConfig(): void
    {
        self::assertStringContainsString(
            "type.document.invoice.route.emails.create",
            $this->serviceProviderContent()
        );
        self::assertStringContainsString(
            "nfse.modals.invoices.emails.create",
            $this->serviceProviderContent()
        );
    }
}
