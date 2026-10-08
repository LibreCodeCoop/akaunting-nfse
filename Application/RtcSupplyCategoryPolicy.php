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

    /** @var array<string,string> */
    private const DATES = [
        self::DIGITAL_PLATFORM => '2026-12-01',
        self::NON_ISS_INTANGIBLE => '2026-12-01',
        self::CONDOMINIUM_REVENUE => '2026-12-01',
        self::LEASE => '2026-12-01',
        self::RESIDUAL_SERVICE => '2026-12-01',
    ];

    /**
     * @return array{required:bool,effective_date:?string,reason:string}
     */
    public function evaluate(string $category, string $competenceDate, int $simplesOption): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competenceDate);
        $validDate = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $competenceDate;

        if (!$validDate || !array_key_exists($category, self::DATES) || !in_array($simplesOption, [1, 2, 3], true)) {
            return ['required' => false, 'effective_date' => null, 'reason' => 'unverifiable'];
        }

        $effective = in_array($simplesOption, [2, 3], true) ? '2027-01-01' : self::DATES[$category];

        return [
            'required' => $date >= new \DateTimeImmutable($effective),
            'effective_date' => $effective,
            'reason' => in_array($simplesOption, [2, 3], true) ? 'simples_nacional' : $category,
        ];
    }
}
