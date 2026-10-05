<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class FiscalGroupResumeTest extends FeatureTestCase
{
    public function testPreviewMarksPreviouslyIssuedGroupAndKeepsOtherGroupSelectable(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $response = $this->loginAs()
            ->get(route('nfse.invoices.service-preview', $invoice))
            ->assertOk()
            ->json();

        $groups = is_array($response['fiscal_groups'] ?? null) ? $response['fiscal_groups'] : [];

        if (count($groups) < 2) {
            self::markTestSkipped('Fixture invoice does not contain multiple fiscal groups.');
        }

        $first = $groups[0];

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '7001',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
            'emission_group_key' => (string) $first['key'],
        ]);

        $updated = $this->loginAs()
            ->get(route('nfse.invoices.service-preview', $invoice))
            ->assertOk()
            ->json();

        $updatedGroups = $updated['fiscal_groups'] ?? [];
        self::assertTrue((bool) ($updatedGroups[0]['issued'] ?? false));
        self::assertSame('7001', $updatedGroups[0]['nfse_number'] ?? null);
        self::assertFalse((bool) ($updatedGroups[1]['issued'] ?? true));
    }
}
