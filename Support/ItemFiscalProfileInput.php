<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class ItemFiscalProfileInput
{
    /**
     * @param mixed $request Akaunting request object carrying item form input.
     * @param array<string,mixed>|null $existing The stored fiscal profile, never a profile inferred from submitted fields.
     * @return null|array{item_lista_servico:?string,codigo_tributacao_nacional:?string,codigo_tributacao_municipal:?string,rtc_supply_category?:?string}
     */
    public static function fromRequest(mixed $request, ?array $existing = null): ?array
    {
        if (!is_object($request) || !method_exists($request, 'input')) {
            return null;
        }

        $serviceKey = 'nfse_item_lista_servico';
        $nationalKey = 'nfse_codigo_tributacao_nacional';
        $municipalKey = 'nfse_codigo_tributacao_municipal';
        $categoryKey = 'nfse_rtc_supply_category';

        $servicePresent = method_exists($request, 'exists')
            ? (bool) $request->exists($serviceKey)
            : $request->input($serviceKey, null) !== null;
        $nationalPresent = method_exists($request, 'exists')
            ? (bool) $request->exists($nationalKey)
            : $request->input($nationalKey, null) !== null;
        $municipalPresent = method_exists($request, 'exists')
            ? (bool) $request->exists($municipalKey)
            : $request->input($municipalKey, null) !== null;

        $categoryPresent = method_exists($request, 'exists')
            ? (bool) $request->exists($categoryKey)
            : $request->input($categoryKey, null) !== null;

        if (!$servicePresent && !$nationalPresent && !$municipalPresent && !$categoryPresent) {
            return null;
        }

        // Akaunting can submit only the fields that changed. Omitted fields
        // must never delete unrelated persisted fiscal data.
        $rawServiceCode = $servicePresent ? $request->input($serviceKey) : ($existing['item_lista_servico'] ?? null);
        $rawNationalCode = $nationalPresent ? $request->input($nationalKey) : ($existing['codigo_tributacao_nacional'] ?? null);
        $rawMunicipalCode = $municipalPresent ? $request->input($municipalKey) : ($existing['codigo_tributacao_municipal'] ?? null);

        $serviceCode = Lc116Code::normalize($rawServiceCode);
        if ($existing !== null && (string) $rawServiceCode === (string) ($existing['item_lista_servico'] ?? '')) {
            $serviceCode = (string) ($existing['item_lista_servico'] ?? '');
        }

        // Preserve a historic code byte-for-byte when the user has not changed
        // it. A new value still follows the current normalization/validation.
        if ($existing !== null && (string) $rawNationalCode === (string) ($existing['codigo_tributacao_nacional'] ?? '')) {
            $nationalCode = (string) ($existing['codigo_tributacao_nacional'] ?? '');
        } else {
            $nationalCode = preg_replace('/\D+/', '', (string) $rawNationalCode) ?: '';
            $nationalCode = $nationalCode !== ''
                ? str_pad(substr($nationalCode, 0, 6), 6, '0', STR_PAD_LEFT)
                : null;
        }

        if ($existing !== null && (string) $rawMunicipalCode === (string) ($existing['codigo_tributacao_municipal'] ?? '')) {
            $municipalCode = (string) ($existing['codigo_tributacao_municipal'] ?? '');
        } else {
            $municipalCode = preg_replace('/\D+/', '', (string) $rawMunicipalCode) ?: '';
            $municipalCode = $municipalCode !== ''
                ? str_pad(substr($municipalCode, 0, 3), 3, '0', STR_PAD_LEFT)
                : null;
        }

        $profile = [
            'item_lista_servico' => $serviceCode !== '' ? $serviceCode : null,
            'codigo_tributacao_nacional' => $nationalCode !== '' ? $nationalCode : null,
            'codigo_tributacao_municipal' => $municipalCode !== '' ? $municipalCode : null,
        ];

        if ($categoryPresent || ($existing !== null && array_key_exists('rtc_supply_category', $existing))) {
            $category = trim((string) ($categoryPresent
                ? $request->input($categoryKey, '')
                : ($existing['rtc_supply_category'] ?? '')));
            $profile['rtc_supply_category'] = $category !== '' ? $category : null;
        }

        return $profile;
    }
}
