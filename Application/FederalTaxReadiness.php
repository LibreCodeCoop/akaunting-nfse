<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure policy for deciding which invoice-level federal taxes must be present
 * before fiscal issuance.
 */
final class FederalTaxReadiness
{
    /**
     * @return list<'pis'|'cofins'|'csll'>
     */
    public function requiredBuckets(
        mixed $enforce,
        string $piscofinsSituation,
        string $retentionType,
    ): array {
        if (!$this->isEnabled($enforce)) {
            return [];
        }

        $required = [];

        if ($piscofinsSituation !== '' && $piscofinsSituation !== '0') {
            $required[] = 'pis';
            $required[] = 'cofins';
        }

        if (in_array($retentionType, ['3', '7', '8', '9'], true)) {
            $required[] = 'csll';
        }

        return $required;
    }

    /**
     * @param array<string,string> $snapshot
     * @param list<string> $requiredBuckets
     * @return array{isReady:bool,missing:list<string>}
     */
    public function evaluate(array $snapshot, array $requiredBuckets): array
    {
        $keys = [
            'pis' => 'pis_value',
            'cofins' => 'cofins_value',
            'irrf' => 'irrf_value',
            'csll' => 'csll_value',
        ];
        $missing = [];

        foreach ($requiredBuckets as $bucket) {
            $snapshotKey = $keys[$bucket] ?? null;

            if ($snapshotKey !== null && trim((string) ($snapshot[$snapshotKey] ?? '')) === '') {
                $missing[] = $bucket;
            }
        }

        return [
            'isReady' => $missing === [],
            'missing' => $missing,
        ];
    }

    private function isEnabled(mixed $configured): bool
    {
        if (is_bool($configured)) {
            return $configured;
        }

        if (is_numeric($configured)) {
            return (int) $configured === 1;
        }

        if (is_string($configured)) {
            $normalized = strtolower(trim($configured));

            if ($normalized === '' || in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return true;
    }
}
