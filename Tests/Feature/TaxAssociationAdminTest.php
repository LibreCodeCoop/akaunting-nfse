<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\TaxFiscalAssociation;
use Tests\Feature\FeatureTestCase;

final class TaxAssociationAdminTest extends FeatureTestCase
{
    public function testOperatorReviewsAndIdempotentlyConfirmsExistingTaxWithoutChangingAccounting(): void
    {
        $this->loginAs();
        $tax = $this->tax((int) company_id(), 'Synthetic confirmed PIS');
        $initial = DB::table('taxes')->where('id', $tax)->first();

        $this->get(route('nfse.settings.tax-associations.index'))
            ->assertOk()
            ->assertSee('Synthetic confirmed PIS')
            ->assertSee('Sem associação confirmada');

        $data = $this->confirmation($tax);
        $url = route('nfse.settings.tax-associations.store');
        $this->post($url, $data)->assertRedirect(route('nfse.settings.tax-associations.index'));
        $this->post($url, $data)->assertRedirect(route('nfse.settings.tax-associations.index'));

        $stored = TaxFiscalAssociation::query()->where('tax_id', $tax)->get();
        self::assertCount(1, $stored);
        self::assertSame('pis', $stored[0]->fiscal_identity);
        self::assertSame('provider_due', $stored[0]->fiscal_role);
        self::assertSame('synthetic-fiscal-assessment', $stored[0]->source_reference);
        self::assertSame((int) auth()->id(), (int) $stored[0]->confirmed_by);
        $after = DB::table('taxes')->where('id', $tax)->first();
        self::assertSame($initial->name, $after->name);
        self::assertSame($initial->rate, $after->rate);
        self::assertSame($initial->type, $after->type);
    }

    public function testForeignCompanyTaxCannotBeListedOrConfirmed(): void
    {
        $this->loginAs();
        $foreign = $this->tax((int) company_id() + 1000, 'Foreign synthetic secret tax');

        $this->get(route('nfse.settings.tax-associations.index'))
            ->assertOk()
            ->assertDontSee('Foreign synthetic secret tax');

        $this->post(route('nfse.settings.tax-associations.store'), $this->confirmation($foreign))
            ->assertSessionHasErrors('tax_id');

        self::assertSame(0, TaxFiscalAssociation::query()->where('tax_id', $foreign)->count());
    }

    public function testExactRetryMustStillProveThatTheTaxBelongsToTheCompany(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $tax = $this->tax($owner, 'Synthetic reassigned tax');
        $url = route('nfse.settings.tax-associations.store');
        $data = $this->confirmation($tax);

        $this->post($url, $data)->assertSessionHasNoErrors();
        DB::table('taxes')->where('id', $tax)->update(['company_id' => $owner + 1000]);
        $this->post($url, $data)->assertSessionHasErrors('tax_id');

        self::assertSame(1, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
    }

    public function testOverlappingOrUnacknowledgedRevisionsAreRejected(): void
    {
        $this->withExceptionHandling();
        $this->loginAs();
        $tax = $this->tax((int) company_id(), 'Synthetic tax');

        $url = route('nfse.settings.tax-associations.store');
        $this->post($url, $this->confirmation($tax))->assertSessionHasNoErrors();

        $other = $this->confirmation($tax);
        $other['fiscal_identity'] = 'cofins';
        $other['effective_from'] = '2026-06-01';
        $this->post($url, $other)->assertSessionHasErrors('tax_id');

        $unconfirmed = $this->confirmation($tax);
        unset($unconfirmed['acknowledge']);
        $this->post($url, $unconfirmed)->assertSessionHasErrors('acknowledge');

        self::assertSame(1, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
    }

    public function testMissingCompanyContextFailsClosed(): void
    {
        $this->loginAs();
        $active = Company::getCurrent();
        self::assertNotNull($active);
        app()->forgetInstance(Company::class);
        try {
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            (new \Modules\Nfse\Http\Controllers\TaxAssociationController())->index();
        } finally {
            app()->instance(Company::class, $active);
        }
    }

    private function tax(int $companyId, string $name): int
    {
        return (int) DB::table('taxes')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'rate' => '0.65',
            'type' => 'normal',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function confirmation(int $tax): array
    {
        return [
            'tax_id' => $tax,
            'fiscal_identity' => 'pis',
            'fiscal_role' => 'provider_due',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'source_reference' => 'synthetic-fiscal-assessment',
            'acknowledge' => '1',
        ];
    }
}
