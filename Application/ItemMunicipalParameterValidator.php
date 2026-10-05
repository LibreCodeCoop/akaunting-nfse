<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Evaluates cached municipal parameters without performing network I/O.
 *
 * Municipal data is advisory for the item profile: a mismatch is surfaced as
 * warning, never silently corrected and never promoted to an objective
 * national-catalog invalidation.
 */
final class ItemMunicipalParameterValidator
{
    /**
     * @param array<string,mixed>|null $payload
     * @param array{source?:mixed,stale?:mixed,fetched_at?:mixed,environment?:mixed}|null $meta
     * @return array{
     *   status:'valid'|'warning'|'unverifiable',
     *   issues:list<string>,
     *   official_rate:?string,
     *   source:array{source:string,stale:bool,fetched_at:string,environment:string}
     * }
     */
    public function validate(?string $configuredRate, ?array $payload, ?array $meta = null): array
    {
        $source = [
            'source' => is_scalar($meta['source'] ?? null) ? trim((string) $meta['source']) : '',
            'stale' => (bool) ($meta['stale'] ?? false),
            'fetched_at' => is_scalar($meta['fetched_at'] ?? null) ? trim((string) $meta['fetched_at']) : '',
            'environment' => is_scalar($meta['environment'] ?? null) ? trim((string) $meta['environment']) : '',
        ];

        if ($payload === null) {
            return [
                'status' => 'unverifiable',
                'issues' => ['municipal_parameters_unavailable'],
                'official_rate' => null,
                'source' => $source,
            ];
        }

        $aliquotas = $payload['aliquota']['aliquotas'] ?? null;

        if (!is_array($aliquotas)) {
            return [
                'status' => 'unverifiable',
                'issues' => ['municipal_rate_unavailable'],
                'official_rate' => null,
                'source' => $source,
            ];
        }

        $rates = [];

        foreach ($aliquotas as $row) {
            $raw = is_array($row) ? ($row['Aliq'] ?? null) : null;

            if (!is_numeric($raw)) {
                continue;
            }

            $normalized = $this->normalizeDecimal((string) $raw);

            if ($normalized !== null) {
                $rates[$normalized] = true;
            }
        }

        $rates = array_keys($rates);

        if (count($rates) !== 1) {
            return [
                'status' => 'unverifiable',
                'issues' => count($rates) > 1
                    ? ['municipal_rate_ambiguous']
                    : ['municipal_rate_unavailable'],
                'official_rate' => null,
                'source' => $source,
            ];
        }

        $officialRate = $rates[0];
        $configured = $this->normalizeDecimal((string) $configuredRate);
        $issues = [];

        if ($configured === null) {
            return [
                'status' => 'unverifiable',
                'issues' => ['item_tax_rate_unverifiable'],
                'official_rate' => $officialRate,
                'source' => $source,
            ];
        }

        if ($configured !== $officialRate) {
            $issues[] = 'municipal_rate_mismatch';
        }

        if ($source['stale']) {
            $issues[] = 'municipal_snapshot_stale';
        }

        return [
            'status' => $issues === [] ? 'valid' : 'warning',
            'issues' => $issues,
            'official_rate' => $officialRate,
            'source' => $source,
        ];
    }

    private function normalizeDecimal(string $value): ?string
    {
        $normalized = str_replace(',', '.', trim($value));

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
