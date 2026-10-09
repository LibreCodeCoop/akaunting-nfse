<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Reads only clearly delimited Brazilian address parts. Ambiguous input must
 * be corrected by the operator, never fabricated for a fiscal document.
 */
final class NationalTakerAddressParser
{
    /** @return array{logradouro:string,numero:string,complemento:string,bairro:string}|null */
    public function parse(string $address): ?array
    {
        $parts = array_map('trim', explode(',', $address));
        if (count($parts) < 3 || count($parts) > 8 || in_array('', $parts, true)) {
            return null;
        }

        $logradouro = array_shift($parts);
        $numero = array_shift($parts);
        $bairro = array_pop($parts);

        if ($logradouro === '' || $bairro === '' || $numero === null
            || preg_match('/^\d{1,8}[A-Za-z]?$/D', $numero) !== 1
            || preg_match('/^(sala|conj|conjunto|apt|apto|andar|bloco|loja|casa|cj)\b/iu', $bairro) === 1) {
            return null;
        }

        return [
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => implode(', ', $parts),
            'bairro' => $bairro,
        ];
    }
}
