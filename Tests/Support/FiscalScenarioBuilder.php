<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;

/**
 * Shared fiscal fixtures for Akaunting Feature tests.
 *
 * Core accounting objects still come from Akaunting factories; this builder
 * only adds module-owned fiscal state so tests do not duplicate persistence
 * details while keeping relevant fiscal values explicit at call sites.
 */
final class FiscalScenarioBuilder
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function invoice(array $attributes = []): Document
    {
        return Document::factory()->invoice()->create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function receipt(
        Document $invoice,
        string $status = 'emitted',
        string $number = '1001',
        ?string $accessKey = null,
        array $attributes = [],
    ): NfseReceipt {
        return NfseReceipt::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'nfse_number' => $number,
            'chave_acesso' => $accessKey ?? str_repeat('1', 50),
            'status' => $status,
        ], $attributes));
    }

    public static function itemProfile(
        Item $item,
        string $serviceCode = '0107',
        string $nationalCode = '010701',
    ): ItemFiscalProfile {
        return ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => $serviceCode,
            'codigo_tributacao_nacional' => $nationalCode,
        ]);
    }
}
