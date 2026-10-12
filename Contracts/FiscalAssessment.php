<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Side-effect-free result envelope. Consumers decide how to apply findings
 * to the applicable operation and emission boundary.
 */
final readonly class FiscalAssessment
{
    /**
     * @param list<FiscalDecision> $decisions
     */
    public function __construct(
        public FiscalContext $context,
        public array $decisions,
    ) {
        foreach ($this->decisions as $decision) {
            if (!$decision instanceof FiscalDecision) {
                throw new \InvalidArgumentException('Decisions must be FiscalDecision instances.');
            }
        }
    }
}
