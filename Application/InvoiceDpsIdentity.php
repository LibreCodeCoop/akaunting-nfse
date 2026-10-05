<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;

/**
 * Stable ordinary-invoice DPS identity and competence mapping.
 *
 * Grouped and substitution flows keep their dedicated identity strategies.
 */
final class InvoiceDpsIdentity
{
    public function series(Invoice $invoice): string
    {
        return '00001';
    }

    public function number(Invoice $invoice): string
    {
        $invoiceId = isset($invoice->id) ? (int) $invoice->id : 0;

        return (string) max($invoiceId, 1);
    }

    public function competenceDate(Invoice $invoice): ?string
    {
        $issuedAt = $invoice->issued_at ?? null;

        if ($issuedAt instanceof \DateTimeInterface) {
            return $issuedAt->format('Y-m-d');
        }

        if (is_string($issuedAt) && $issuedAt !== '') {
            $timestamp = strtotime($issuedAt);

            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return null;
    }
}
