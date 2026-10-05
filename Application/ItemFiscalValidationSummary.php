<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class ItemFiscalValidationSummary
{
    /**
     * @param array<string,mixed> $national
     * @param array<string,mixed> $municipal
     * @return array<string,mixed>
     */
    public function combine(array $national, array $municipal): array
    {
        $nationalStatus = (string) ($national['status'] ?? 'unverifiable');
        $municipalStatus = (string) ($municipal['status'] ?? 'unverifiable');

        $status = match (true) {
            $nationalStatus === 'invalid' => 'invalid',
            $nationalStatus === 'warning' || $municipalStatus === 'warning' => 'warning',
            $nationalStatus === 'unverifiable' || $municipalStatus === 'unverifiable' => 'unverifiable',
            default => 'valid',
        };

        return array_merge($national, [
            'status' => $status,
            'municipal_status' => $municipalStatus,
            'municipal_issues' => array_values(
                is_array($municipal['issues'] ?? null) ? $municipal['issues'] : [],
            ),
            'municipal_official_rate' => is_string($municipal['official_rate'] ?? null)
                ? $municipal['official_rate']
                : null,
            'municipal_source' => is_array($municipal['source'] ?? null)
                ? $municipal['source']
                : [],
        ]);
    }
}
