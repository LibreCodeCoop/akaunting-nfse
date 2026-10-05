<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Tests\Feature\FeatureTestCase;

final class LegacyInvoiceRouteRedirectTest extends FeatureTestCase
{
    public function testLegacyInvoiceListRedirectsToNativeAkauntingList(): void
    {
        $this->loginAs()
            ->get(route('nfse.invoices.index'))
            ->assertRedirect(route('invoices.index'));
    }

    public function testLegacyPendingListRedirectsToNativeAkauntingList(): void
    {
        $this->loginAs()
            ->get(route('nfse.invoices.pending'))
            ->assertRedirect(route('invoices.index'));
    }

    public function testLegacyInvoiceDetailRedirectsToNativeAkauntingInvoice(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $this->loginAs()
            ->get(route('nfse.invoices.show', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));
    }

    public function testNfseRootStillRendersFiscalDashboard(): void
    {
        $this->loginAs()
            ->get(route('nfse.dashboard.index'))
            ->assertOk();
    }
}
