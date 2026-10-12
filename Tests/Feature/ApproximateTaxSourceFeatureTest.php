<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\ApproximateTaxSource;
use Modules\Nfse\Support\ApproximateTaxSourceResolver;
use Tests\Feature\FeatureTestCase;

final class ApproximateTaxSourceFeatureTest extends FeatureTestCase
{
    public function testVersionedSourcesSelectActualCompetenceWithoutChangingPreviousRevision(): void
    {
        $companyId = (int) Document::factory()->invoice()->create()->company_id;
        $original = $this->source($companyId, '2026-01-01', '3.20', 'version-1');
        $later = $this->source($companyId, '2026-10-01', '4.15', 'version-2');
        $resolver = new ApproximateTaxSourceResolver();

        $september = $resolver->resolve($companyId, '010701', 1, '2026-09-30');
        $october = $resolver->resolve($companyId, '010701', 1, '2026-10-01');

        self::assertSame('3.20', $september['municipal']);
        self::assertSame((int) $original->id, $september['source']['id']);
        self::assertSame('4.15', $october['municipal']);
        self::assertSame((int) $later->id, $october['source']['id']);
        self::assertSame('3.20', (string) $original->fresh()->municipal_percent);
        self::assertSame('', $resolver->resolve($companyId + 1, '010701', 1, '2026-10-01')['municipal']);
        self::assertSame('', $resolver->resolve($companyId, '010701', 2, '2026-10-01')['municipal']);
        self::assertSame('', $resolver->resolve($companyId, '010702', 1, '2026-10-01')['municipal']);
    }

    private function source(int $companyId, string $from, string $municipal, string $version): ApproximateTaxSource
    {
        return ApproximateTaxSource::query()->create([
            'company_id' => $companyId,
            'national_service_code' => '010701',
            'simples_option' => 1,
            'competence_from' => $from,
            'source_kind' => 'independent_estimate',
            'source_name' => 'Instituto sintético de referência',
            'source_version' => $version,
            'federal_percent' => '6.25',
            'state_percent' => '0.00',
            'municipal_percent' => $municipal,
        ]);
    }
}
