<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Verified rule decision or explicit missing-evidence diagnostic; no implicit emission approval.
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
        public ?string $sourceUrl,
        public ?string $sourceProvision,
        public ?string $sourceVersion,
        public ?\DateTimeImmutable $sourcePublishedAt,
        public ?\DateTimeImmutable $effectiveFrom,
        public ?\DateTimeImmutable $effectiveUntil,
        public ?string $fieldPath,
        public ?string $correctionCode,
        public ?int $expectedCents = null,
        public ?int $observedCents = null,
    ) {
        if (!in_array($this->category, ['normative', 'calculation', 'document', 'protocol'], true)
            || !in_array($this->status, ['pass', 'fail', 'not_applicable', 'unverifiable', 'unsupported'], true)
            || !in_array($this->severity, ['block', 'warn', 'info'], true)) {
            throw new \InvalidArgumentException('Unknown fiscal decision category, status or severity.');
        }

        if (trim($this->ruleId) === '') {
            throw new \InvalidArgumentException('Fiscal decision rule ID is required.');
        }

        foreach ([$this->sourceUrl, $this->sourceProvision, $this->sourceVersion] as $evidence) {
            if ($evidence !== null && trim($evidence) === '') {
                throw new \InvalidArgumentException('Known source evidence cannot be blank.');
            }
        }

        if (in_array($this->status, ['pass', 'fail', 'not_applicable'], true)
            && ($this->sourceUrl === null || $this->sourceProvision === null
                || $this->sourceVersion === null || $this->sourcePublishedAt === null
                || $this->effectiveFrom === null)) {
            throw new \InvalidArgumentException('Conclusive decisions require versioned source evidence and applicability.');
        }

        if ($this->severity === 'block' && in_array($this->status, ['pass', 'not_applicable'], true)) {
            throw new \InvalidArgumentException('A passing or non-applicable rule cannot be blocking.');
        }

        if ($this->effectiveUntil !== null
            && ($this->effectiveFrom === null || $this->effectiveUntil < $this->effectiveFrom)) {
            throw new \InvalidArgumentException('Effective end requires a start and cannot precede it.');
        }
    }
}
