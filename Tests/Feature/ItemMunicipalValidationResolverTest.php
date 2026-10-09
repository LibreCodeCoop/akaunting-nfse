<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\MunicipalParameterSnapshot;
use Modules\Nfse\Support\ItemMunicipalValidationResolver;
use Tests\Feature\FeatureTestCase;

final class ItemMunicipalValidationResolverTest extends FeatureTestCase
{
    public function testUsesExactHistoricalCompetenceAndPreservesQueryProvenance(): void
    {
        $item = Item::factory()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '123',
        ]);

        foreach ([
            ['2026-02-01', 2, '2026-02-02 12:00:00'],
            ['2026-03-01', 5, '2026-03-02 12:00:00'],
        ] as [$competence, $rate, $fetchedAt]) {
            MunicipalParameterSnapshot::query()->create([
                'company_id' => $item->company_id,
                'environment' => 'production',
                'municipio_ibge' => '3303302',
                'service_code' => '010701123',
                'competence_date' => $competence,
                'payload' => ['aliquota' => ['aliquotas' => [['Aliq' => $rate]]]],
                'source_provenance' => [
                    'endpoints' => ['aliquota' => [
                        'source_url' => 'https://adn.nfse.gov.br/parametrizacao/3303302/01.07.01.123/' . $competence . '/aliquota',
                        'http_status' => null,
                        'outcome' => 'decoded_response',
                    ]],
                ],
                'fetched_at' => $fetchedAt,
            ]);
        }

        $resolver = new ItemMunicipalValidationResolver();
        $february = $resolver->resolve(
            itemId: $item->id,
            companyId: $item->company_id,
            nationalCode: '010701',
            municipioIbge: '3303302',
            sandboxMode: false,
            competence: '2026-02-01',
        );
        $march = $resolver->resolve(
            itemId: $item->id,
            companyId: $item->company_id,
            nationalCode: '010701',
            municipioIbge: '3303302',
            sandboxMode: false,
            competence: '2026-03-01',
        );
        self::assertSame('2.00', $february['official_rate']);
        self::assertSame('5.00', $march['official_rate']);
        self::assertSame('2026-02-01', $february['source']['competence']);
        self::assertSame('3303302', $february['source']['queried_municipality']);
        self::assertSame('010701123', $february['source']['service_code']);
        self::assertSame((int) $item->company_id, $february['source']['company_id']);
        self::assertSame('production', $february['source']['environment']);
        self::assertSame('decoded_response', $february['source']['endpoint_responses']['aliquota']['outcome']);
        self::assertSame('unverifiable', $february['decision']);
        self::assertSame(2, MunicipalParameterSnapshot::query()->count());
    }

    public function testDifferentMunicipalityEnvironmentServiceAndCompetenceCannotUseSnapshot(): void
    {
        $item = Item::factory()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '123',
        ]);

        MunicipalParameterSnapshot::query()->create([
            'company_id' => $item->company_id,
            'environment' => 'production',
            'municipio_ibge' => '3303302',
            'service_code' => '010701123',
            'competence_date' => '2026-02-01',
            'payload' => ['aliquota' => ['aliquotas' => [['Aliq' => 2]]]],
            'fetched_at' => '2026-02-02 12:00:00',
        ]);
        $resolver = new ItemMunicipalValidationResolver();
        foreach ([
            ['3304557', false, '2026-02-01', '010701'],
            ['3303302', true, '2026-02-01', '010701'],
            ['3303302', false, '2026-02-02', '010701'],
            ['3303302', false, '2026-02-01', '010101'],
        ] as [$municipality, $sandbox, $competence, $national]) {
            $result = $resolver->resolve(
                itemId: $item->id,
                companyId: $item->company_id,
                nationalCode: $national,
                municipioIbge: $municipality,
                sandboxMode: $sandbox,
                competence: $competence,
            );
            self::assertSame('unverifiable', $result['decision']);
            self::assertNull($result['official_rate']);
            self::assertContains('municipal_parameters_unavailable', $result['issues']);
        }
    }
}
