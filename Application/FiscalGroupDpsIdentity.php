<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\FiscalGroupIdentity;

/**
 * Allocates a stable DPS identity for one fiscal group of an Akaunting invoice.
 *
 * Grouped emissions use DPS series 00002, separate from the normal invoice
 * series 00001. The registry row id is then a stable, retry-safe DPS number.
 */
final class FiscalGroupDpsIdentity
{
    public const SERIES = '00002';

    /**
     * @return array{series:string,number:string}
     */
    public function forGroup(int $invoiceId, string $groupKey): array
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invoice id must be positive.');
        }

        $groupKey = trim($groupKey);

        if ($groupKey === '') {
            throw new \InvalidArgumentException('Fiscal group key cannot be empty.');
        }

        $identity = DB::transaction(function () use ($invoiceId, $groupKey): FiscalGroupIdentity {
            DB::table('documents')
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();

            $existing = FiscalGroupIdentity::query()
                ->where('invoice_id', $invoiceId)
                ->where('group_key', $groupKey)
                ->first();

            if ($existing instanceof FiscalGroupIdentity) {
                return $existing;
            }

            return FiscalGroupIdentity::query()->create([
                'invoice_id' => $invoiceId,
                'group_key' => $groupKey,
            ]);
        });

        $number = (string) $identity->id;

        if (strlen($number) > 15) {
            throw new \OverflowException('Fiscal group identity exceeds the 15-digit DPS number limit.');
        }

        return [
            'series' => self::SERIES,
            'number' => $number,
        ];
    }
}
