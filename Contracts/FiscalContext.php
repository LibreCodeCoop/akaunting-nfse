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
     * @param list<FiscalTaxFact>|null $taxFacts
     */
    public function __construct(
        public int $companyId,
        public string $fiscalGroupKey,
        public ?\DateTimeImmutable $taxableEventDate,
        public ?\DateTimeImmutable $competenceDate,
        public ?string $providerRegime,
        public ?string $operationCategory,
        public ?string $lc116Subitem,
        public ?string $nationalTaxCode,
        public ?string $nbsCode,
        public ?string $incidenceMunicipalityIbge,
        public ?string $takerType,
        public ?string $takerMunicipalityIbge,
        public ?string $takerCountryIso2,
        public ?bool $takerIsSimples,
        public ?int $grossServiceCents,
        public ?array $taxFacts = null,
        public ?bool $providerIsCooperative = null,
        public ?int $paymentAmountCents = null,
        public ?\DateTimeImmutable $paymentDate = null,
        /** Observed DPS opSimpNac (null = unknown, not a default non-optant). */
        public ?int $opcaoSimplesNacional = null,
        /** Observed DPS regApTribSN; protocol capability is evaluated separately. */
        public ?int $regimeApuracaoTributariaSn = null,
        /** Observed DPS regEspTrib: null is not the official explicit code 0. */
        public ?int $regimeEspecialTributacao = null,
        /** Verified 2027 IBS/CBS collection mode; null when not established. */
        public ?string $ibsCbsRegime = null,
        /** Taker's verified sphere; never assume the taker is the actual payer. */
        public ?FiscalGovernmentSphere $takerGovernmentSphere = null,
        /** Observed CNPJ legal-nature code; null when not verified. */
        public ?string $takerLegalNatureCode = null,
        /** Null when the actual payer has not been identified as the taker. */
        public ?bool $payerIsTaker = null,
        /** Final-consumer sale is a transaction fact, not inferred from CPF/CNPJ. */
        public ?bool $saleToFinalConsumer = null,
        /** Specific IBS/CBS cooperative regime facts; never inferred from legal form. */
        public ?CooperativeIbsCbsFacts $cooperativeIbsCbsFacts = null,
        /** Verified counterparty type for member-to-cooperative supplies. */
        public ?bool $takerIsCooperative = null,
    ) {
        if ($this->companyId <= 0 || trim($this->fiscalGroupKey) === '') {
            throw new \InvalidArgumentException('Company and fiscal-group identity are required.');
        }

        if ($this->grossServiceCents !== null && $this->grossServiceCents < 0) {
            throw new \InvalidArgumentException('Gross service amount cannot be negative.');
        }

        if ($this->paymentAmountCents !== null && $this->paymentAmountCents < 0) {
            throw new \InvalidArgumentException('Payment amount cannot be negative.');
        }

        if ($this->opcaoSimplesNacional !== null
            && !in_array($this->opcaoSimplesNacional, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Unknown opSimpNac code.');
        }

        if ($this->ibsCbsRegime !== null
            && !in_array($this->ibsCbsRegime, ['simples', 'regular'], true)) {
            throw new \InvalidArgumentException('Unknown IBS/CBS collection mode.');
        }

        if ($this->takerLegalNatureCode !== null
            && preg_match('/^[0-9]{4}$/D', $this->takerLegalNatureCode) !== 1) {
            throw new \InvalidArgumentException('Taker legal-nature code must have four digits.');
        }

        foreach ($this->taxFacts ?? [] as $fact) {
            if (!$fact instanceof FiscalTaxFact) {
                throw new \InvalidArgumentException('Tax facts must be FiscalTaxFact instances.');
            }
        }
    }
}
