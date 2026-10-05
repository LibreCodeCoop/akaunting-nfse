<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Converts item-profile validation into the emission blocking contract.
 *
 * Only objectively invalid values block issuance. Missing optional values and
 * advisory/non-normative correlations remain warning/unverifiable states.
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
    public function evaluate(array $profile): array
    {
        $validation = ($this->validator ?? new ItemFiscalProfileValidator())->validate(
            is_scalar($profile['item_lista_servico'] ?? null)
                ? (string) $profile['item_lista_servico']
                : null,
            is_scalar($profile['codigo_tributacao_nacional'] ?? null)
                ? (string) $profile['codigo_tributacao_nacional']
                : null,
        );

        return array_merge(
            ['isReady' => $validation['status'] !== 'invalid'],
            $validation,
        );
    }
}
