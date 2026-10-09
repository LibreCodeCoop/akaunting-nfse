<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Models\MunicipalParameterSnapshot;

/**
 * Advisory official municipal-query cache, keyed by the exact fiscal context.
 *
 * The provenance is historical evidence of a *consultation*, never an issuer
 * authorization, rejection or a municipality-wide code blacklist.
 */
final class MunicipalParameterSnapshotStore
{
    /**
     * @param callable():array<string,mixed>|MunicipalParameterConsultation $fetch
     * @return array{data:array<string,mixed>,meta:array<string,mixed>}
     */
    public function resolve(
        int $companyId,
        string $environment,
        string $municipioIbge,
        string $serviceCode,
        string $competence,
        callable $fetch,
    ): array {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competence);
        if ($companyId <= 0
            || !in_array($environment, ['sandbox', 'production'], true)
            || preg_match('/^\d{7}$/D', $municipioIbge) !== 1
            || preg_match('/^\d{9}$/D', $serviceCode) !== 1
            || !$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $competence) {
            throw new \InvalidArgumentException('Invalid municipal snapshot identity.');
        }

        try {
            $resolved = $fetch();
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

            return [
                'data' => is_array($snapshot->payload) ? $snapshot->payload : [],
                'meta' => $this->metadata(
                    $companyId,
                    $environment,
                    $municipioIbge,
                    $serviceCode,
                    $competence,
                    'cache',
                    true,
                    $snapshot->fetched_at?->toAtomString() ?? '',
                    is_array($snapshot->source_provenance) ? $snapshot->source_provenance : [],
                    'official_query_failed',
                ),
            ];
        }

        $data = $resolved instanceof MunicipalParameterConsultation ? $resolved->data : $resolved;
        $provenance = $resolved instanceof MunicipalParameterConsultation ? $resolved->provenance : [];
        $fetchedAt = new \DateTimeImmutable('now');
        $timestamp = $fetchedAt->format(DATE_ATOM);

        try {
            MunicipalParameterSnapshot::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'environment' => $environment,
                    'municipio_ibge' => $municipioIbge,
                    'service_code' => $serviceCode,
                    'competence_date' => $competence,
                ],
                [
                    'payload' => $data,
                    'source_provenance' => $provenance !== [] ? $provenance : null,
                    'fetched_at' => $fetchedAt,
                ],
            );
        } catch (\Throwable) {
            // Cache persistence is advisory. An official successful response
            // must remain usable when a migration or cache is unavailable.
        }

        return [
            'data' => $data,
            'meta' => $this->metadata(
                $companyId,
                $environment,
                $municipioIbge,
                $serviceCode,
                $competence,
                'live',
                false,
                $timestamp,
                $provenance,
                null,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $provenance
     * @return array<string,mixed>
     */
    private function metadata(
        int $companyId,
        string $environment,
        string $municipioIbge,
        string $serviceCode,
        string $competence,
        string $source,
        bool $stale,
        string $fetchedAt,
        array $provenance,
        ?string $fallbackReason,
    ): array {
        $endpoints = is_array($provenance['endpoints'] ?? null) ? $provenance['endpoints'] : [];
        $aliquota = is_array($endpoints['aliquota'] ?? null) ? $endpoints['aliquota'] : [];

        return [
            'source' => $source,
            'stale' => $stale,
            'fetched_at' => $fetchedAt,
            'company_id' => $companyId,
            'environment' => $environment,
            'municipio_ibge' => $municipioIbge,
            'service_code' => $serviceCode,
            'competence' => $competence,
            'http_status' => is_int($aliquota['http_status'] ?? null) ? $aliquota['http_status'] : null,
            'endpoint_responses' => $endpoints,
            'contract_version' => is_string($provenance['contract_version'] ?? null)
                ? $provenance['contract_version']
                : null,
            'valid_from' => is_string($provenance['valid_from'] ?? null) ? $provenance['valid_from'] : null,
            'valid_until' => is_string($provenance['valid_until'] ?? null) ? $provenance['valid_until'] : null,
            'fallback_reason' => $fallbackReason,
        ];
    }
}
