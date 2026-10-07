<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class ItemFiscalProfileInput
{
    /**
     * @param mixed $request Akaunting request object carrying item form input.
     * @return null|array{item_lista_servico:?string,codigo_tributacao_nacional:?string,codigo_tributacao_municipal:?string}
     */
    public static function fromRequest(mixed $request): ?array
    {
        if (!is_object($request) || !method_exists($request, 'input')) {
            return null;
        }

        $serviceKey = 'nfse_item_lista_servico';
        $nationalKey = 'nfse_codigo_tributacao_nacional';
        $municipalKey = 'nfse_codigo_tributacao_municipal';

        $servicePresent = method_exists($request, 'exists')
            ? (bool) $request->exists($serviceKey)
            : $request->input($serviceKey, null) !== null;
        $nationalPresent = method_exists($request, 'exists')
            ? (bool) $request->exists($nationalKey)
            : $request->input($nationalKey, null) !== null;
        $municipalPresent = method_exists($request, 'exists')
            ? (bool) $request->exists($municipalKey)
            : $request->input($municipalKey, null) !== null;

        if (!$servicePresent && !$nationalPresent && !$municipalPresent) {
            return null;
        }

        $rawServiceCode = $request->input($serviceKey, null);
        $rawNationalCode = $request->input($nationalKey, null);
        $rawMunicipalCode = $request->input($municipalKey, null);

        $serviceCode = Lc116Code::normalize($rawServiceCode);

        $nationalCode = preg_replace('/\D+/', '', (string) $rawNationalCode) ?: '';
        $nationalCode = $nationalCode !== ''
            ? str_pad(substr($nationalCode, 0, 6), 6, '0', STR_PAD_LEFT)
            : null;

        $municipalCode = preg_replace('/\D+/', '', (string) $rawMunicipalCode) ?: '';
        $municipalCode = $municipalCode !== ''
            ? str_pad(substr($municipalCode, 0, 3), 3, '0', STR_PAD_LEFT)
            : null;

        return [
            'item_lista_servico' => $serviceCode !== '' ? $serviceCode : null,
            'codigo_tributacao_nacional' => $nationalCode,
            'codigo_tributacao_municipal' => $municipalCode,
        ];
    }
}
