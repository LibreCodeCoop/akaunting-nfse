<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class ItemFiscalProfileInput
{
    /**
     * @param mixed $request Akaunting request object carrying item form input.
     * @return null|array{item_lista_servico:?string,codigo_tributacao_nacional:?string}
     */
    public static function fromRequest(mixed $request): ?array
    {
        if (!is_object($request) || !method_exists($request, 'input')) {
            return null;
        }

        $rawServiceCode = $request->input('nfse_item_lista_servico', null);
        $rawNationalCode = $request->input('nfse_codigo_tributacao_nacional', null);

        if ($rawServiceCode === null && $rawNationalCode === null) {
            return null;
        }

        $serviceDigits = preg_replace('/\D+/', '', (string) $rawServiceCode) ?: '';
        $serviceCode = preg_match('/(\d{4})$/', $serviceDigits, $serviceCodeMatch)
            ? $serviceCodeMatch[1]
            : substr($serviceDigits, 0, 4);

        $nationalCode = preg_replace('/\D+/', '', (string) $rawNationalCode) ?: '';
        $nationalCode = $nationalCode !== ''
            ? str_pad(substr($nationalCode, 0, 6), 6, '0', STR_PAD_LEFT)
            : null;

        return [
            'item_lista_servico' => $serviceCode !== '' ? $serviceCode : null,
            'codigo_tributacao_nacional' => $nationalCode,
        ];
    }
}
