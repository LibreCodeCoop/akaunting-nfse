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
        public ?FiscalTaxRole $fiscalRole = null,
        /** Provenance of the observed amount, not a classification hint. */
        public ?string $origin = null,
        /** Observed tax-specific base in cents, distinct from invoice total. */
        public ?int $baseCents = null,
        /** Observed percentage as a decimal string (never a float). */
        public ?string $ratePercent = null,
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

        if ($this->origin !== null && trim($this->origin) === '') {
            throw new \InvalidArgumentException('Tax amount provenance cannot be empty.');
        }

        if ($this->ratePercent !== null
            && preg_match('/^\\d+(?:\\.\\d{1,6})?$/D', $this->ratePercent) !== 1) {
            throw new \InvalidArgumentException('Tax percentage must be an exact decimal string.');
        }
    }
}
