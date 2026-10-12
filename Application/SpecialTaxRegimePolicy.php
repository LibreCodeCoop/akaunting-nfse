<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * National DPS regEspTrib rules independent of municipal consultative evidence.
 *
 * XSD v1.01 requires the field; null (not configured) is never equivalent to
 * the official code 0 (Nenhum). Municipality-specific eligibility remains
 * unverified until the versioned fiscal matrix supplies a binding assessment.
 */
final class SpecialTaxRegimePolicy
{
    /** @var list<int> */
    public const OFFICIAL_CODES = [0, 1, 2, 3, 4, 5, 6, 9];

    /**
     * @return array{code:?int,state:'missing'|'invalid'|'unsupported'|'none'|'special',can_emit:bool,reason:string,municipal_review_required:bool}
     */
    public function evaluate(mixed $rawCode, int $simples, int $issqn, ?string $competence = null): array
    {
        if ($rawCode === null || $rawCode === '') {
            return $this->result(null, 'missing', false, 'regime_not_configured');
        }

        if (!is_int($rawCode) && !is_string($rawCode)) {
            return $this->result(null, 'invalid', false, 'invalid_regime_code');
        }

        $value = is_string($rawCode) ? trim($rawCode) : (string) $rawCode;

        if (preg_match('/^[0-9]$/D', $value) !== 1 || !in_array((int) $value, self::OFFICIAL_CODES, true)) {
            return $this->result(null, 'invalid', false, 'invalid_regime_code');
        }

        $code = (int) $value;

        if (!in_array($simples, [1, 2, 3], true) || !in_array($issqn, [1, 2, 3, 4], true)) {
            return $this->result($code, 'invalid', false, 'invalid_tax_context');
        }

        if ($code === 0) {
            return $this->result(0, 'none', true, 'explicit_none');
        }

        if ($issqn !== 1) {
            // Anexo I, E0172: immune, export and non-incidence services use 0.
            return $this->result($code, 'invalid', false, 'non_taxable_requires_none');
        }

        if ($simples === 2) {
            // Anexo I, E0174: MEI is not entitled to a nonzero regEspTrib.
            return $this->result($code, 'invalid', false, 'mei_requires_none');
        }

        if ($simples === 3) {
            // E0175 depends on regApTribSN, which the installed DPS DTO does not
            // expose. This is a runtime limitation, NOT a universal legal ban.
            return $this->result($code, 'unsupported', false, 'simples_apuration_not_supported');
        }

        if ($competence !== null && !$this->isValidDate($competence)) {
            return $this->result($code, 'invalid', false, 'invalid_competence');
        }

        // Annex I v1.01 (09/02/2026): E0176 verifies an autonomous
        // professional's valid municipal enrollment or a permissive parameter;
        // E0177/E0178 verify municipality, taxpayer, service and competence.
        // The older cross-municipality E0176 wording must not be used here.
        // Missing preventive evidence is not an authenticated SEFIN rejection.
        return $this->result($code, 'special', true, 'municipal_evidence_unverified', true);
    }

    public function forIssuance(mixed $code, int $simples, int $issqn, ?string $competence): int
    {
        $assessment = $this->evaluate($code, $simples, $issqn, $competence);

        if ($assessment['can_emit'] && $assessment['code'] !== 0 && !$this->isValidDate($competence ?? '')) {
            throw new \InvalidArgumentException('Invalid special tax regime: missing_or_invalid_competence');
        }

        if (!$assessment['can_emit'] || $assessment['code'] === null) {
            throw new \InvalidArgumentException('Invalid special tax regime: ' . $assessment['reason']);
        }

        return $assessment['code'];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    /**
     * @return array{code:?int,state:'missing'|'invalid'|'unsupported'|'none'|'special',can_emit:bool,reason:string,municipal_review_required:bool}
     */
    private function result(?int $code, string $state, bool $canEmit, string $reason, bool $municipalReview = false): array
    {
        return [
            'code' => $code,
            'state' => $state,
            'can_emit' => $canEmit,
            'reason' => $reason,
            'municipal_review_required' => $municipalReview,
        ];
    }
}
