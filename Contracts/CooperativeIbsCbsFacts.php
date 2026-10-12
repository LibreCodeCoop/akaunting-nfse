<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Evidence supplied by the fiscal adapter for the LC 214/2025 art. 271 option.
 * No tax rate, legal eligibility or source identity is inferred here.
 * The relevant cooperative may be the provider or the taker.
 */
final readonly class CooperativeIbsCbsFacts
{
    public function __construct(
        /** Verified conclusion about the relevant cooperative's election. */
        public ?bool $optionConfirmed = null,
        /** From which date a confirmed option may produce effects. */
        public ?DateTimeImmutable $optionEffectiveFrom = null,
        /** Procedural proof is separate from confirmed option effectiveness. */
        public ?bool $optionFormFiled = null,
        public ?bool $associatesListFiled = null,
        /** A described relationship does not prove legal entitlement. */
        public ?CooperativeSupplyRelation $supplyRelation = null,
        /** Must be verified on the transaction's legally relevant date. */
        public ?bool $associationVerifiedAtEvent = null,
        /** Relevant when a cooperative supplies to its associate. */
        public ?bool $associateIsRegularIbsCbsTaxpayer = null,
        /** The regulation excludes operations outside the cooperative's purposes. */
        public ?bool $withinCooperativePurpose = null,
        /** A personal-use exclusion is separate from associated-party status. */
        public ?bool $forPersonalUseOrConsumption = null,
    ) {
        if ($this->optionConfirmed === true && $this->optionEffectiveFrom === null) {
            throw new InvalidArgumentException('Confirmed option requires an effective start date.');
        }

        if ($this->optionConfirmed === true
            && ($this->optionFormFiled !== true || $this->associatesListFiled !== true)) {
            throw new InvalidArgumentException('Confirmed option contradicts missing mandatory procedure.');
        }
    }
}
