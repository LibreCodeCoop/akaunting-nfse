<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Code;

/**
 * Determines when the IBS/CBS group becomes mandatory for LC 116 services.
 *
 * Sources:
 * - Ato Conjunto RFB/CGIBS nº 4/2026, art. 1º, III.
 * - CGNFS-e guidance published on 2026-08-07.
 * - Resolução CGSN nº 191/2026 for Simples Nacional transition.
 *
 * The module currently emits LC 116 service operations. Categories from the
 * Ato Conjunto that are not representable by an LC 116 item (for example
 * leases and non-ISS intangible supplies) are intentionally outside this
 * policy until the module models those operations explicitly.
 */
final class IbsCbsObligationPolicy
{
    private const GENERAL_LC116_EFFECTIVE_DATE = '2026-10-01';

    private const DEFERRED_LC116_EFFECTIVE_DATE = '2026-12-01';

    private const SIMPLES_EFFECTIVE_DATE = '2027-01-01';

    /** @var list<string> */
    private const DEFERRED_LC116_ITEMS = ['0103', '0105', '0109', '1601'];

    /**
     * @return array{required:bool,effective_date:?string,reason:string}
     */
    public function evaluate(
        string $competenceDate,
        int $opcaoSimplesNacional,
        string $itemListaServico,
    ): array {
        $service = Lc116Code::normalize($itemListaServico);
        $competence = $this->date($competenceDate);

        if (
            $service === ''
            || $competence === null
            || !in_array($opcaoSimplesNacional, [1, 2, 3], true)
        ) {
            return [
                'required' => false,
                'effective_date' => null,
                'reason' => 'unverifiable',
            ];
        }

        if (in_array($opcaoSimplesNacional, [2, 3], true)) {
            return $this->fromEffectiveDate($competence, self::SIMPLES_EFFECTIVE_DATE, 'simples_nacional');
        }

        if (in_array($service, self::DEFERRED_LC116_ITEMS, true)) {
            return $this->fromEffectiveDate($competence, self::DEFERRED_LC116_EFFECTIVE_DATE, 'deferred_lc116_service');
        }

        return $this->fromEffectiveDate($competence, self::GENERAL_LC116_EFFECTIVE_DATE, 'general_lc116_service');
    }

    /**
     * @return array{required:bool,effective_date:string,reason:string}
     */
    private function fromEffectiveDate(\DateTimeImmutable $competence, string $effectiveDate, string $reason): array
    {
        $effective = new \DateTimeImmutable($effectiveDate);

        return [
            'required' => $competence >= $effective,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
        ];
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }
}
