<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Observed Akaunting tax-row fact, not a legal tax classification.
 * An absent ID remains null; labels and percentages are never identity.
 */
final readonly class FiscalTaxFact
{
    public function __construct(
        public int $documentItemId,
        public ?int $taxId,
        public ?int $documentItemTaxRowId,
        public string $accountingType,
        public int $amountCents,
        public ?string $fiscalTaxCode = null,
        public ?string $fiscalRole = null,
    ) {
        if ($this->documentItemId <= 0) {
            throw new \InvalidArgumentException('Document item ID must be positive.');
        }

        if ($this->taxId !== null && $this->taxId <= 0) {
            throw new \InvalidArgumentException('Tax ID must be positive when known.');
        }

        if ($this->documentItemTaxRowId !== null && $this->documentItemTaxRowId <= 0) {
            throw new \InvalidArgumentException('Tax row ID must be positive when known.');
        }
    }
}
