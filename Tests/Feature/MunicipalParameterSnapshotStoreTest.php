<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Modules\Nfse\Models\MunicipalParameterSnapshot;
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
            serviceCode: '010701',
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
        self::assertSame('010701', $snapshot->service_code);
        self::assertSame('2026-10-05', $snapshot->competence_date?->format('Y-m-d'));
    }

    public function testNetworkFailureReturnsLastSnapshotAsStaleWithoutOverwritingIt(): void
    {
        $snapshot = MunicipalParameterSnapshot::query()->create([
            'company_id' => 1,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701',
            'competence_date' => '2026-10-05',
            'payload' => ['aliquota' => ['aliquota' => '2.00']],
            'fetched_at' => '2026-10-04 12:00:00',
        ]);

        $result = (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701',
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
            serviceCode: '010701',
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
            'service_code' => '010701',
            'competence_date' => '2026-10-05',
            'payload' => ['aliquota' => ['aliquota' => '2.00']],
            'fetched_at' => '2026-10-04 12:00:00',
        ]);

        $this->expectException(\RuntimeException::class);

        (new MunicipalParameterSnapshotStore())->resolve(
            companyId: 1,
            environment: 'sandbox',
            municipioIbge: '3303302',
            serviceCode: '010701',
            competence: '2026-10-05',
            fetch: static function (): array {
                throw new \RuntimeException('ADN unavailable');
            },
        );
    }
}
