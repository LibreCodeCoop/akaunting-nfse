<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/** Prevents SEFIN E0234 for remote services when the domestic taker address is absent. */
final class TakerAddressReadiness
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $ibsCbs
     * @return list<string>
     */
    public function missing(array $payload, array $ibsCbs): array
    {
        if (($ibsCbs['enabled'] ?? false) !== true
            || ($ibsCbs['ibsCbsCodigoIndicadorOperacao'] ?? '') !== '100301') {
            return [];
        }

        $fields = [
            'codigo_municipio' => 'município IBGE',
            'cep' => 'CEP',
            'logradouro' => 'logradouro',
            'numero' => 'número',
            'bairro' => 'bairro',
        ];

        $missing = [];
        foreach ($fields as $key => $label) {
            if (trim((string) ($payload[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
