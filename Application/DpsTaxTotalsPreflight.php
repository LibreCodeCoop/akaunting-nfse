<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Protects the irreversible DPS allocator from known SEFIN E0713/E0712.
 *
 * The mandatory totTrib XSD group cannot use indTotTrib for a non-Simples
 * issuer or ME/EPP. A complete, documented approximate-tax source must be
 * resolved by InvoiceFederalPayloadResolver before a new fiscal reservation.
 */
final class DpsTaxTotalsPreflight
{
    public function assertForNewDps(
        int $simplesOption,
        string $federal,
        string $state,
        string $municipal,
    ): void {
        if (!in_array($simplesOption, [1, 3], true)) {
            return;
        }

        if ($federal === '' || $state === '' || $municipal === '') {
            $code = $simplesOption === 1 ? 'E0713' : 'E0712';
            throw new FiscalIssuanceBlocked(
                'NFSE-APPROXIMATE-TAX-SOURCE-REQUIRED: ' . $code
                . ' forbids indTotTrib for this fiscal regime. Register documented federal, '
                . 'state and municipal approximate-tax totals for the invoice competence '
                . 'before requesting a new DPS. Never infer tax percentages from withholding or ISS.',
            );
        }
    }
}
