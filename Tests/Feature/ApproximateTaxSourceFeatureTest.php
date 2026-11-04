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

    public function testAuthenticatedOperatorAppendsVersionedSourceInsteadOfOverwritingEarlierValue(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $companyId = (int) $invoice->company_id;
        $this->loginAs();

        $payload = [
            'national_service_code' => '010701',
            'simples_option' => '1',
            'competence_from' => '2026-10-01',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Provedor sintético',
            'source_version' => 'v1-synthetic',
            'source_reference' => 'synthetic-archive-entry/estimates-v1',
            'federal_percent' => '7,20',
            'state_percent' => '0,00',
            'municipal_percent' => '3,40',
        ];

        $this->post(route('nfse.settings.approximate-taxes.store'), $payload)
            ->assertRedirect(route('nfse.settings.approximate-taxes'));

        self::assertSame('7.20', (string) ApproximateTaxSource::query()
            ->where('company_id', $companyId)
            ->firstOrFail()
            ->federal_percent);

        $payload['source_version'] = 'v2-synthetic';
        $payload['municipal_percent'] = '4,10';
        $this->post(route('nfse.settings.approximate-taxes.store'), $payload)
            ->assertRedirect(route('nfse.settings.approximate-taxes'));

        self::assertSame(2, ApproximateTaxSource::query()->where('company_id', $companyId)->count());
        self::assertSame('3.40', (string) ApproximateTaxSource::query()
            ->where('company_id', $companyId)
            ->where('source_version', 'v1-synthetic')
            ->firstOrFail()->municipal_percent);

        $this->get(route('nfse.settings.approximate-taxes'))
            ->assertOk()
            ->assertSee('id="approximate-taxes-body"', false)
            ->assertDontSee('v1-synthetic');

        $this->get(route('nfse.settings.approximate-taxes.resources'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source_version', 'v2-synthetic')
            ->assertJsonPath('data.1.source_version', 'v1-synthetic')
            ->assertJsonPath('data.1.source_reference', 'synthetic-archive-entry/estimates-v1');

        unset($payload['source_reference']);
        $payload['source_version'] = 'v3-without-evidence';
        $this->withExceptionHandling()
            ->post(route('nfse.settings.approximate-taxes.store'), $payload)
            ->assertSessionHasErrors('source_reference');
        self::assertSame(2, ApproximateTaxSource::query()->where('company_id', $companyId)->count());
    }

    public function testJsonSourceSubmissionIsAppendOnlyAndInvalidValuesDoNotPersist(): void
    {
        $this->withExceptionHandling()->loginAs();
        $payload = [
            'national_service_code' => '010701',
            'simples_option' => '1',
            'competence_from' => '2026-11-01',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Synthetic estimate institution',
            'source_version' => 'synthetic-api-v1',
            'source_reference' => 'synthetic-review-evidence-1',
            'federal_percent' => '7.50',
            'state_percent' => '0.00',
            'municipal_percent' => '2.25',
        ];
        $url = route('nfse.settings.approximate-taxes.store');
        $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.source_version', 'synthetic-api-v1');
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->get(route('nfse.settings.approximate-taxes.resources', ['limit' => 1]))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.federal_percent', '7.50');
        $this->get(route('nfse.settings.approximate-taxes.resources', ['limit' => 101]))
            ->assertUnprocessable();

        $payload['source_version'] = 'synthetic-api-invalid-zero';
        $payload['source_kind'] = 'documented_zero';
        $this->postJson($url, $payload)->assertUnprocessable();
        self::assertSame(1, ApproximateTaxSource::query()
            ->where('company_id', (int) company_id())->count());
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
            'source_reference' => 'synthetic-archive-entry/' . $version,
            'federal_percent' => '6.25',
            'state_percent' => '0.00',
            'municipal_percent' => $municipal,
        ]);
    }
}
