<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * RTC effective dates for explicitly classified non-LC116 supply operations.
 *
 * Source: CGNFS-e guidance of 2026-08-07, based on Ato Conjunto
 * RFB/CGIBS 4/2026. This policy does not infer a category from fiscal codes,
 * taxpayer identity, description, or an LC 116 profile.
 */
final class RtcSupplyCategoryPolicy
{
    public const DIGITAL_PLATFORM = 'digital_platform';
    public const NON_ISS_INTANGIBLE = 'non_iss_intangible';
    public const CONDOMINIUM_REVENUE = 'condominium_revenue';
    public const LEASE = 'lease';
    public const RESIDUAL_SERVICE = 'residual_service';

    /**
     * Canonical December-category table shared by the legacy date adapter
     * and the typed FiscalAssessment consumer. Provisions distinguish the
     * legal basis even when several categories share the same calendar date.
     *
     * @var array<string,array{effective_date:string,provision:string}>
     */
    private const RULES = [
        self::DIGITAL_PLATFORM => ['effective_date' => '2026-12-01', 'provision' => 'art. 1, III, a'],
        self::NON_ISS_INTANGIBLE => ['effective_date' => '2026-12-01', 'provision' => 'art. 1, III, e'],
        self::CONDOMINIUM_REVENUE => ['effective_date' => '2026-12-01', 'provision' => 'art. 1, III, f'],
        self::LEASE => ['effective_date' => '2026-12-01', 'provision' => 'art. 1, III, g'],
        self::RESIDUAL_SERVICE => ['effective_date' => '2026-12-01', 'provision' => 'art. 1, III, h'],
    ];

    /** @return array{effective_date:string,provision:string}|null */
    public static function rule(string $category): ?array
    {
        return self::RULES[$category] ?? null;
    }

    /**
     * @return array{required:bool,effective_date:?string,reason:string}
     */
    public function evaluate(string $category, string $competenceDate, int $simplesOption): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competenceDate);
        $validDate = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $competenceDate;

        $rule = self::rule($category);
        if (!$validDate || $rule === null || !in_array($simplesOption, [1, 2, 3], true)) {
            return ['required' => false, 'effective_date' => null, 'reason' => 'unverifiable'];
        }

        $effective = in_array($simplesOption, [2, 3], true) ? '2027-01-01' : $rule['effective_date'];

        return [
            'required' => $date >= new \DateTimeImmutable($effective),
            'effective_date' => $effective,
            'reason' => in_array($simplesOption, [2, 3], true) ? 'simples_nacional' : $category,
        ];
    }
}
