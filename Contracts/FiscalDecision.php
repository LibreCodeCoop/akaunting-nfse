<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * One source-backed, time-scoped diagnostic; no implicit emission approval.
 */
final readonly class FiscalDecision
{
    /**
     * @param 'normative'|'calculation'|'document'|'protocol' $category
     * @param 'pass'|'fail'|'not_applicable'|'unverifiable'|'unsupported' $status
     * @param 'block'|'warn'|'info' $severity
     */
    public function __construct(
        public string $ruleId,
        public string $category,
        public string $status,
        public string $severity,
        public string $sourceUrl,
        public string $sourceProvision,
        public string $sourceVersion,
        public \DateTimeImmutable $sourcePublishedAt,
        public \DateTimeImmutable $effectiveFrom,
        public ?\DateTimeImmutable $effectiveUntil,
        public ?string $fieldPath,
        public ?string $correctionCode,
        public ?int $expectedCents = null,
        public ?int $observedCents = null,
    ) {
        if (trim($this->ruleId) === '' || trim($this->sourceUrl) === ''
            || trim($this->sourceProvision) === '' || trim($this->sourceVersion) === '') {
            throw new \InvalidArgumentException('Rule ID and versioned source evidence are required.');
        }

        if (!in_array($this->category, ['normative', 'calculation', 'document', 'protocol'], true)
            || !in_array($this->status, ['pass', 'fail', 'not_applicable', 'unverifiable', 'unsupported'], true)
            || !in_array($this->severity, ['block', 'warn', 'info'], true)) {
            throw new \InvalidArgumentException('Unknown fiscal decision category, status or severity.');
        }

        if ($this->effectiveUntil !== null && $this->effectiveUntil < $this->effectiveFrom) {
            throw new \InvalidArgumentException('Effective end cannot precede effective start.');
        }
    }
}
