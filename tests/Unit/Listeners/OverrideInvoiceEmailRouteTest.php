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
        self::assertStringContainsString("'type.document.invoice.translation.send_mail' => 'nfse::general.invoices.emit_now'", $content);
    }

    public function testListenerGuardsTheOverrideBehindNfseEligibility(): void
    {
        $content = $this->listenerContent();

        self::assertStringContainsString("if (!\$this->moduleIsEnabled('nfse'))", $content);
        self::assertStringContainsString('public function overrideForInvoice', $content);
        self::assertStringContainsString('shouldManageInvoiceSendFlow', $content);
        self::assertStringContainsString('isOperationalSetupReadyForInvoice', $content);
        self::assertStringContainsString('if (!$this->isOperationalSetupReadyForInvoice($invoice))', $content);
        self::assertStringContainsString('hasExistingReceipt($invoice) || $this->hasActiveCompanyService($invoice)', $content);
        self::assertStringContainsString("->where('is_default', true)", $content);
        self::assertStringContainsString('hasCertificateSecret', $content);
        self::assertStringContainsString("if ((\$invoice->type ?? '') !== 'invoice')", $content);
    }
}
