<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Listeners;

use Modules\Nfse\Tests\TestCase;

final class OverrideInvoiceEmailRouteTest extends TestCase
{
    private function listenerContent(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/Listeners/OverrideInvoiceEmailRoute.php'
        );
    }

    public function testListenerTargetsOnlyInvoiceShowRoute(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString("if (\$route->getName() !== 'invoices.show')", $content);
        self::assertStringContainsString("\$invoice = \$route->parameter('invoice');", $content);
        self::assertStringContainsString('overrideForInvoice($invoice);', $content);
        self::assertStringContainsString('config([', $content);
        self::assertStringContainsString("'type.document.invoice.route.emails.create' => 'nfse.modals.invoices.emails.create'", $content);
        self::assertStringContainsString("'type.document.invoice.translation.send_mail' => \$this->sendButtonTranslationKey(\$invoice)", $content);
    }

    public function testListenerGuardsTheOverrideBehindNfseEligibility(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString("if (!\$this->moduleIsEnabled('nfse'))", $content);
        self::assertStringContainsString('public function overrideForInvoice', $content);
        self::assertStringContainsString('shouldManageInvoiceSendFlow', $content);
        self::assertStringContainsString('latestReceiptStatus', $content);
        self::assertStringContainsString("if ((\$invoice->type ?? '') !== 'invoice')", $content);
    }

    public function testEmittedReceiptUsesCancelLabelInsteadOfEmitLabel(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString("if (\$receiptStatus === 'emitted')", $content);
        self::assertStringContainsString("return 'nfse::general.invoices.cancel';", $content);
    }

    public function testCancelledReceiptUsesReemitLabel(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString("if (\$receiptStatus === 'cancelled')", $content);
        self::assertStringContainsString("return 'nfse::general.invoices.reemit';", $content);
    }

    public function testPendingInvoiceWithActiveServiceStillUsesNfseFlow(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString('return $receiptStatus !== null || $this->hasActiveCompanyService($invoice);', $content);
        self::assertStringContainsString("return 'nfse::general.invoices.emit_now';", $content);
    }

    /**
     * The route override must NOT depend on vault availability.
     * hasCertificateSecret performs a network call; if vault is down the button
     * must still be overridden so the user reaches the NFS-e modal instead of
     * the default Akaunting send-email modal.
     */
    public function testRouteOverrideDoesNotDependOnOperationalSetupOrVault(): void
    {
        $content = $this->listenerContent();

        // shouldManageInvoiceSendFlow must NOT call isOperationalSetupReadyForInvoice.
        // The operational-readiness check belongs inside the emit flow, not here.
        self::assertStringNotContainsString(
            'if (!$this->isOperationalSetupReadyForInvoice($invoice))',
            $content,
            'shouldManageInvoiceSendFlow must not gate the override on operational setup; ' .
            'vault connectivity is checked at emit time, not at button render time.'
        );
    }

    /**
     * Once shouldManageInvoiceSendFlow returns true, overrideForInvoice must
     * set both config keys so the button label AND the modal URL are replaced.
     */
    public function testOverrideSetsBothConfigKeys(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString('config([', $content);
        self::assertStringContainsString(
            "'type.document.invoice.route.emails.create' => 'nfse.modals.invoices.emails.create'",
            $content
        );
        self::assertStringContainsString(
            "'type.document.invoice.translation.send_mail' => \$this->sendButtonTranslationKey(\$invoice)",
            $content
        );
    }

    public function testOverrideKeepsModuleRouteForEmittedReceipt(): void
    {
        self::assertStringContainsString(
            'return $receiptStatus !== null || $this->hasActiveCompanyService($invoice);',
            $this->listenerContent()
        );
    }

    public function testListenerReadsLatestReceiptStatusBeforeChoosingTheOverride(): void
    {
        self::assertStringContainsString('protected function latestReceiptStatus(object $invoice): ?string', $this->listenerContent());
        self::assertStringContainsString('protected function sendButtonTranslationKey(object $invoice): string', $this->listenerContent());
    }
}
