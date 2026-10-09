<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Modules\Nfse\Models\MunicipalParameterSnapshot;
use Modules\Nfse\Support\MunicipalParameterConsultation;
use Modules\Nfse\Support\MunicipalParameterSnapshotStore;
use Tests\Feature\FeatureTestCase;

final class MunicipalParameterSnapshotStoreTest extends FeatureTestCase
{
    public function testSuccessfulOfficialQueryPersistsSnapshotWithProvenance(): void
    {
        $result = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-10-05',
            fetch: static fn (): array => ['aliquota' => ['aliquota' => '2.00']],
        );

        self::assertSame('live', $result['meta']['source']);
        self::assertFalse($result['meta']['stale']);
        self::assertNotSame('', $result['meta']['fetched_at']);

        $snapshot = MunicipalParameterSnapshot::query()->firstOrFail();

        self::assertSame(1, $snapshot->company_id);
        self::assertSame('sandbox', $snapshot->environment);
        self::assertSame('3303302', $snapshot->municipio_ibge);
        self::assertSame('010701000', $snapshot->service_code);
        self::assertSame('2026-10-05', $snapshot->competence_date?->format('Y-m-d'));
    }

    public function testNetworkFailureReturnsLastSnapshotAsStaleWithoutOverwritingIt(): void
    {
        $snapshot = MunicipalParameterSnapshot::query()->create([
            'company_id' => 1,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701000',
            'competence_date' => '2026-10-05',
            'payload' => ['aliquota' => ['aliquota' => '2.00']],
            'fetched_at' => '2026-10-04 12:00:00',
        ]);

        $result = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-10-05',
            fetch: static function (): array {
                throw new \RuntimeException('ADN unavailable');
            },
        );

        self::assertSame('cache', $result['meta']['source']);
        self::assertTrue($result['meta']['stale']);
        self::assertSame('2.00', $result['data']['aliquota']['aliquota']);
        self::assertSame(
            ['aliquota' => ['aliquota' => '2.00']],
            $snapshot->fresh()->payload,
        );
    }

    public function testSuccessfulLiveQueryDoesNotDependOnCachePersistence(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('nfse_municipal_parameter_snapshots');

        $result = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-10-05',
            fetch: static fn (): array => ['aliquota' => ['aliquota' => '2.00']],
        );

        self::assertSame('live', $result['meta']['source']);
        self::assertFalse($result['meta']['stale']);
        self::assertSame('2.00', $result['data']['aliquota']['aliquota']);
    }

    public function testFailureWithoutMatchingCompanySnapshotPropagates(): void
    {
        MunicipalParameterSnapshot::query()->create([
            'company_id' => 2,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701000',
            'competence_date' => '2026-10-05',
            'payload' => ['aliquota' => ['aliquota' => '2.00']],
            'fetched_at' => '2026-10-04 12:00:00',
        ]);

        $this->expectException(\RuntimeException::class);

        (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-10-05',
            fetch: static function (): array {
                throw new \RuntimeException('ADN unavailable');
            },
        );
    }
    public function testPersistsKnownHttp404AndUnknownSuccessfulStatusWithoutInventingSchemaVersion(): void
    {
        $provenance = [
            'endpoints' => [
                'aliquota' => [
                    'source_url' => 'https://adn.producaorestrita.nfse.gov.br/parametrizacao/3303302/01.07.01.000/2026-09-01/aliquota',
                    'http_status' => 404,
                    'outcome' => 'http_404',
                ],
                'convenio' => [
                    'source_url' => 'https://adn.producaorestrita.nfse.gov.br/parametrizacao/3303302/convenio',
                    'http_status' => null,
                    'outcome' => 'decoded_response',
                ],
            ],
            'contract_version' => null,
            'valid_from' => null,
            'valid_until' => null,
        ];

        $resolved = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-09-01',
            fetch: static fn (): MunicipalParameterConsultation => new MunicipalParameterConsultation(
                data: ['aliquota' => []],
                provenance: $provenance,
            ),
        );

        self::assertSame('live', $resolved['meta']['source']);
        self::assertSame(404, $resolved['meta']['http_status']);
        self::assertNull($resolved['meta']['contract_version']);
        self::assertSame('2026-09-01', $resolved['meta']['competence']);
        self::assertSame($provenance, MunicipalParameterSnapshot::query()->sole()->source_provenance);
        self::assertNull($resolved['meta']['endpoint_responses']['convenio']['http_status']);
    }

    public function testNetworkFallbackReusesExactContextAndPreservesRecordedProvenance(): void
    {
        $snapshot = MunicipalParameterSnapshot::query()->create([
            'company_id' => 11,
            'environment' => 'production',
            'municipio_ibge' => '3303302',
            'service_code' => '010701123',
            'competence_date' => '2026-02-15',
            'payload' => ['aliquota' => ['aliquotas' => [['Aliq' => 2]]]],
            'source_provenance' => [
                'endpoints' => ['aliquota' => ['http_status' => 404, 'outcome' => 'http_404']],
            ],
            'fetched_at' => '2026-02-16 12:00:00',
        ]);

        $store = new MunicipalParameterSnapshotStore();
        $args = [
            'companyId' => 11,
            'environment' => 'production',
            'municipioIbge' => '3303302',
            'serviceCode' => '010701123',
            'competence' => '2026-02-15',
        ];
        $failedFetch = static function (): array {
            throw new \RuntimeException('simulated transport outage');
        };
        $fallback = $store->resolve(...$args, fetch: $failedFetch);

        self::assertSame('cache', $fallback['meta']['source']);
        self::assertTrue($fallback['meta']['stale']);
        self::assertSame('official_query_failed', $fallback['meta']['fallback_reason']);
        self::assertSame(404, $fallback['meta']['http_status']);
        self::assertSame('2026-02-15', $fallback['meta']['competence']);
        self::assertSame('2026-02-16 12:00:00', $snapshot->fresh()->fetched_at?->format('Y-m-d H:i:s'));

        foreach ([
            ['companyId' => 12],
            ['environment' => 'sandbox'],
            ['municipioIbge' => '3304557'],
            ['serviceCode' => '010701222'],
            ['competence' => '2026-02-16'],
        ] as $change) {
            try {
                $store->resolve(...array_merge($args, $change), fetch: $failedFetch);
                self::fail('The cache may not leak across a different fiscal context.');
            } catch (\RuntimeException $error) {
                self::assertSame('simulated transport outage', $error->getMessage());
            }
        }

        self::assertSame(1, MunicipalParameterSnapshot::query()->count());
    }

    public function testLegacySnapshotKeepsUnknownProvenanceWhenUsedForFallback(): void
    {
        MunicipalParameterSnapshot::query()->create([
            'company_id' => 1,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701000',
            'competence_date' => '2026-01-01',
            'payload' => ['aliquota' => []],
            'fetched_at' => '2026-01-02 10:00:00',
        ]);

        $resolved = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701000',
            competence: '2026-01-01',
            fetch: static function (): array {
                throw new \RuntimeException('timeout');
            },
        );

        self::assertSame([], $resolved['meta']['endpoint_responses']);
        self::assertNull($resolved['meta']['http_status']);
        self::assertNull($resolved['meta']['contract_version']);
    }

}
