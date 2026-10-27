<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;

/**
 * One fiscal catalog representation for session and native API transports.
 * Authorization belongs to the corresponding route middleware.
 */
final class NationalServicesController extends Controller
{
    public function nationalServices(Request $request, OfficialDomainCatalog $catalog): JsonResponse
    {
        $rawLc = $request->query('lc116', '');
        $rawQuery = $request->query('q', '');
        $lc = is_string($rawLc) ? Lc116Code::normalize($rawLc) : '';
        $query = is_string($rawQuery) ? trim($rawQuery) : '';

        if ($lc !== '' && preg_match('/^\\d{4}$/', $lc) !== 1) {
            return response()->json(['data' => []], 422);
        }

        if (mb_strlen($query) > 100) {
            return response()->json(['data' => []], 422);
        }

        // The official catalog API applies a maximum result cap; querying by LC
        // prefix first preserves related codes before additional text filtering.
        if ($lc === '' && mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $entries = $catalog->searchNationalServices($lc !== '' ? $lc : $query, 500);
        if ($lc !== '') {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => str_starts_with($entry['code'], $lc)
            ));
        }
        if ($query !== '' && $lc !== '') {
            $term = mb_strtolower($query, 'UTF-8');
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool =>
                    str_contains(mb_strtolower($entry['code'] . ' ' . $entry['description'], 'UTF-8'), $term)
            ));
        }

        return response()->json(['data' => array_slice($entries, 0, 50)]);
    }

}
