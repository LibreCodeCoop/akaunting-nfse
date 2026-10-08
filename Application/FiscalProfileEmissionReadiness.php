<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Converts item-profile validation into the emission blocking contract.
 *
 * Objectively invalid values block issuance. The national taxation code is
 * also required for DPS XML emission because SEFIN rejects an empty cTribNac.
 * Advisory/non-normative correlations remain warning/unverifiable states.
 */
final class FiscalProfileEmissionReadiness
{
    public function __construct(
        private readonly ?ItemFiscalProfileValidator $validator = null,
    ) {
    }

    /**
     * @param array{item_lista_servico?:mixed,codigo_tributacao_nacional?:mixed} $profile
     * @return array{
     *   isReady:bool,
     *   status:'valid'|'warning'|'invalid'|'unverifiable',
     *   issues:list<string>,
     *   correlation_status:'unverifiable',
     *   source_versions:array<string,string>
     * }
     */
    public function evaluate(array $profile, ?array $municipal = null): array
    {
        $validation = ($this->validator ?? new ItemFiscalProfileValidator())->validate(
            is_scalar($profile['item_lista_servico'] ?? null)
                ? (string) $profile['item_lista_servico']
                : null,
            is_scalar($profile['codigo_tributacao_nacional'] ?? null)
                ? (string) $profile['codigo_tributacao_nacional']
                : null,
        );

        $category = trim((string) ($profile['rtc_supply_category'] ?? ''));
        if ($category !== '' && $category !== 'ordinary_lc116') {
            return array_merge(
                $validation,
                [
                    'isReady' => false,
                    'status' => 'unverifiable',
                    'issues' => array_merge($validation['issues'], ['unsupported_rtc_supply_category']),
                ],
            );
        }

        $issues = is_array($validation['issues'] ?? null)
            ? array_values(array_map('strval', $validation['issues']))
            : [];

        $missingRequiredNationalCode = in_array('missing_national_code', $issues, true)
            || in_array('missing_profile', $issues, true);

        $municipalRejection = ($municipal['decision'] ?? '') === 'rejected'
            && ($municipal['binding'] ?? '') === 'exact'
            && ($municipal['can_attempt'] ?? true) === false;

        return array_merge(
            $validation,
            [
                'isReady' => $validation['status'] !== 'invalid' && !$missingRequiredNationalCode && !$municipalRejection,
                'municipal_decision' => (string) ($municipal['decision'] ?? 'unverifiable'),
                'municipal_issues' => $municipalRejection ? ['sefin_e0312_this_dps'] : [],
            ],
        );
    }
}
