<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Models\MunicipalParameterSnapshot;

final class MunicipalParameterSnapshotStore
{
    /**
     * @param callable():array<string,mixed> $fetch
     * @return array{
     *   data:array<string,mixed>,
     *   meta:array{source:string,stale:bool,fetched_at:string,environment:string}
     * }
     */
    public function resolve(
        int $companyId,
        string $environment,
        string $municipioIbge,
        string $serviceCode,
        string $competence,
        callable $fetch,
    ): array {
        try {
            $data = $fetch();
        } catch (\Throwable $fetchError) {
            try {
                $snapshot = MunicipalParameterSnapshot::query()
                    ->where('company_id', $companyId)
                    ->where('environment', $environment)
                    ->where('municipio_ibge', $municipioIbge)
                    ->where('service_code', $serviceCode)
                    ->whereDate('competence_date', $competence)
                    ->first();
            } catch (\Throwable) {
                throw $fetchError;
            }

            if (!$snapshot instanceof MunicipalParameterSnapshot) {
                throw $fetchError;
            }

            $payload = is_array($snapshot->payload) ? $snapshot->payload : [];

            return [
                'data' => $payload,
                'meta' => $this->metadata($snapshot, 'cache', true),
            ];
        }

        $fetchedAt = now();

        try {
            $snapshot = MunicipalParameterSnapshot::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'environment' => $environment,
                    'municipio_ibge' => $municipioIbge,
                    'service_code' => $serviceCode,
                    'competence_date' => $competence,
                ],
                [
                    'payload' => $data,
                    'fetched_at' => $fetchedAt,
                ],
            );

            return [
                'data' => $data,
                'meta' => $this->metadata($snapshot, 'live', false),
            ];
        } catch (\Throwable) {
            // Snapshot persistence is advisory. A successful official query
            // must never become an operational failure because the cache is
            // unavailable or has not been migrated yet.
            return [
                'data' => $data,
                'meta' => [
                    'source' => 'live',
                    'stale' => false,
                    'fetched_at' => $fetchedAt->toAtomString(),
                    'environment' => $environment,
                ],
            ];
        }
    }

    /**
     * @return array{source:string,stale:bool,fetched_at:string,environment:string}
     */
    private function metadata(
        MunicipalParameterSnapshot $snapshot,
        string $source,
        bool $stale,
    ): array {
        return [
            'source' => $source,
            'stale' => $stale,
            'fetched_at' => $snapshot->fetched_at?->toAtomString() ?? '',
            'environment' => (string) $snapshot->environment,
        ];
    }
}
