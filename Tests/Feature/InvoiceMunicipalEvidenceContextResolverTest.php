<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\MunicipalAdmissibilityEvaluator;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Support\InvoiceMunicipalEvidenceContextResolver;
use Tests\Feature\FeatureTestCase;

final class InvoiceMunicipalEvidenceContextResolverTest extends FeatureTestCase
{
    public function testUsesSelectedPersistedGroupAndActualInvoiceCompetenceNotToday(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->forceFill(['issued_at' => '2026-02-15 12:00:00'])->save();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        foreach (['123', '222'] as $suffix) {
            $item = Item::factory()->create(['company_id' => $invoice->company_id]);
            $invoice->items()->create([
                'company_id' => $invoice->company_id,
                'type' => 'item',
                'item_id' => $item->id,
                'name' => 'Servico ' . $suffix,
                'quantity' => 1,
                'price' => 100,
                'total' => 100,
            ]);
            ItemFiscalProfile::query()->create([
                'company_id' => $invoice->company_id,
                'item_id' => $item->id,
                'item_lista_servico' => '0107',
                'codigo_tributacao_nacional' => '010701',
                'codigo_tributacao_municipal' => $suffix,
            ]);
        }

        $invoice->load('items');
        $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        self::assertCount(2, $groups);

        $resolver = new InvoiceMunicipalEvidenceContextResolver();
        $settings = ['municipio_ibge' => '3303302', 'sandbox_mode' => true, 'tributacao_issqn' => 1];
        $facts = ['provider_establishment_confirmed' => true];

        $first = $resolver->resolve($invoice, $groups[0], $settings, $facts);
        $second = $resolver->resolve($invoice, $groups[1], $settings, $facts);

        self::assertSame('2026-02-15', $first['competence']);
        self::assertSame((int) $invoice->company_id, $first['company_id']);
        self::assertSame('sandbox', $first['environment']);
        self::assertSame('3303302', $first['incidence_municipality']);
        self::assertSame(['010701123', '010701222'], [$first['service_code'], $second['service_code']]);
        self::assertSame('unverifiable', (new MunicipalAdmissibilityEvaluator())->evaluate($first)['decision']);
    }

    public function testIncidenceExceptionNeedsDocumentedOtherMunicipality(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->forceFill(['issued_at' => '2026-03-15 12:00:00'])->save();
        $group = [
            'item_lista_servico' => '0702',
            'codigo_tributacao_nacional' => '070201',
            'codigo_tributacao_municipal' => '',
        ];
        $settings = ['municipio_ibge' => '3303302', 'sandbox_mode' => false];
        $resolver = new InvoiceMunicipalEvidenceContextResolver();

        $unknown = $resolver->resolve($invoice, $group, $settings, [
            'provider_establishment_confirmed' => true,
        ]);
        self::assertSame('', $unknown['incidence_municipality']);
        self::assertSame('unverifiable', (new MunicipalAdmissibilityEvaluator())->evaluate($unknown)['decision']);

        $explicit = $resolver->resolve($invoice, $group, $settings, [
            'incidence_municipality' => '3304557',
            'incidence_basis' => 'documented_fiscal_context',
        ]);
        self::assertSame('3304557', $explicit['incidence_municipality']);
        self::assertSame('production', $explicit['environment']);
        self::assertSame('070201000', $explicit['service_code']);
        self::assertSame('2026-03-15', $explicit['competence']);
    }
}
