<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Immutable facts for a single company and fiscal group.
 * No rule selection, XML emission, tax calculation or I/O occurs here.
 */
final readonly class FiscalContext
{
    /**
     * @param list<FiscalTaxFact> $taxFacts
     */
    public function __construct(
        public int $companyId,
        public string $fiscalGroupKey,
        public \DateTimeImmutable $taxableEventDate,
        public \DateTimeImmutable $competenceDate,
        public string $providerRegime,
        public string $operationCategory,
        public ?string $lc116Subitem,
        public ?string $nationalTaxCode,
        public ?string $nbsCode,
        public ?string $incidenceMunicipalityIbge,
        public string $takerType,
        public ?string $takerMunicipalityIbge,
        public ?string $takerCountryIso2,
        public ?bool $takerIsSimples,
        public int $grossServiceCents,
        public array $taxFacts = [],
    ) {
        if ($this->companyId <= 0 || trim($this->fiscalGroupKey) === '') {
            throw new \InvalidArgumentException('Company and fiscal-group identity are required.');
        }

        foreach ($this->taxFacts as $fact) {
            if (!$fact instanceof FiscalTaxFact) {
                throw new \InvalidArgumentException('Tax facts must be FiscalTaxFact instances.');
            }
        }
    }
}
