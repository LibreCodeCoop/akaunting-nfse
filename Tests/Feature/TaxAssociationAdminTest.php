<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Common\Company;
use App\Traits\Permissions;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\TaxFiscalAssociation;
use Tests\Feature\FeatureTestCase;

final class TaxAssociationAdminTest extends FeatureTestCase
{
    use Permissions;
    public function testOperatorReviewsAndIdempotentlyConfirmsExistingTaxWithoutChangingAccounting(): void
    {
        $this->loginAs();
        $tax = $this->tax((int) company_id(), 'Synthetic confirmed PIS');
        $initial = DB::table('taxes')->where('id', $tax)->first();

        $this->get(route('nfse.settings.tax-associations.index'))
            ->assertOk()
            ->assertSee('id="tax-associations-body"', false)
            ->assertDontSee('Synthetic confirmed PIS');

        $this->get(route('nfse.settings.tax-associations.resources'))
            ->assertOk()
            ->assertJsonPath('data.taxes.0.name', 'Synthetic confirmed PIS')
            ->assertJsonCount(0, 'data.taxes.0.revisions');

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
        $this->get(route('nfse.settings.tax-associations.resources'))
            ->assertJsonPath('data.taxes.0.revisions.0.fiscal_identity', 'pis')
            ->assertJsonPath('data.taxes.0.revisions.0.fiscal_role', 'provider_due');
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
        $this->get(route('nfse.settings.tax-associations.resources'))
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

    public function testJsonConfirmationRetainsAuditAndRejectsForeignTax(): void
    {
        $this->withExceptionHandling()->loginAs();
        $tax = $this->tax((int) company_id(), 'Synthetic API tax');
        $payload = $this->confirmation($tax);
        $url = route('nfse.settings.tax-associations.store');

        $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.tax_id', $tax)
            ->assertJsonPath('data.already_confirmed', false);
        $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.already_confirmed', true);
        self::assertSame(1, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());

        $other = $this->confirmation($tax);
        $other['fiscal_identity'] = 'cofins';
        $this->postJson($url, $other)->assertStatus(409);
        $foreign = $this->tax((int) company_id() + 1000, 'Synthetic foreign API tax');
        $this->postJson($url, $this->confirmation($foreign))->assertStatus(409);
        $this->get(route('nfse.settings.tax-associations.resources'))
            ->assertOk()
            ->assertJsonPath('data.taxes.0.revisions.0.confirmed_by', (int) auth()->id())
            ->assertDontSee('Synthetic foreign API tax');
        self::assertSame(0, TaxFiscalAssociation::query()->where('tax_id', $foreign)->count());
    }

    public function testOperatorExplicitlyRevisesAnAssociationWithoutMutatingAccounting(): void
    {
        $this->withExceptionHandling()->loginAs();
        $tax = $this->tax((int) company_id(), 'Synthetic revision tax');
        $taxBefore = DB::table('taxes')->where('id', $tax)->first();
        $this->postJson(route('nfse.settings.tax-associations.store'), $this->confirmation($tax))
            ->assertCreated();
        $original = TaxFiscalAssociation::query()->where('tax_id', $tax)->firstOrFail();
        $cutover = now()->addDays(2)->toDateString();
        $payload = $this->confirmation($tax);
        $payload['fiscal_identity'] = 'cofins';
        $payload['effective_from'] = $cutover;
        $payload['source_reference'] = 'synthetic-revision-evidence';
        unset($payload['tax_id']);
        $url = route('nfse.settings.tax-associations.revise', ['associationId' => $original->id]);

        $this->patchJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.already_confirmed', false);
        $this->patchJson($url, $payload)->assertOk()
            ->assertJsonPath('data.already_confirmed', true);
        $this->get(route('nfse.settings.tax-associations.resources'))
            ->assertOk()
            ->assertJsonCount(2, 'data.taxes.0.revisions')
            ->assertJsonPath('data.taxes.0.revisions.0.fiscal_identity', 'pis')
            ->assertJsonPath('data.taxes.0.revisions.1.fiscal_identity', 'cofins')
            ->assertJsonPath('data.taxes.0.revisions.1.source_reference', 'synthetic-revision-evidence');
        self::assertSame(now()->addDay()->toDateString(), $original->fresh()->effective_until);
        self::assertEquals($taxBefore, DB::table('taxes')->where('id', $tax)->first());
        self::assertSame(2, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
    }

    public function testRevisionEndpointRejectsForeignTaxAndInvalidOrPastChanges(): void
    {
        $this->withExceptionHandling()->loginAs();
        $company = (int) company_id();
        $tax = $this->tax($company, 'Synthetic protected tax');
        $this->postJson(route('nfse.settings.tax-associations.store'), $this->confirmation($tax))
            ->assertCreated();
        $original = TaxFiscalAssociation::query()->where('tax_id', $tax)->firstOrFail();
        $payload = $this->confirmation($tax);
        $payload['fiscal_identity'] = 'cofins';
        $payload['effective_from'] = now()->addDays(2)->toDateString();
        $url = route('nfse.settings.tax-associations.revise', ['associationId' => $original->id]);

        $unacknowledged = $payload;
        unset($unacknowledged['acknowledge']);
        $this->patchJson($url, $unacknowledged)->assertUnprocessable()
            ->assertJsonValidationErrors('acknowledge');

        $past = $payload;
        $past['effective_from'] = now()->subDay()->toDateString();
        $this->patchJson($url, $past)->assertStatus(409)
            ->assertJsonPath('reason', 'tax_association_conflict');

        $foreignTax = $this->tax($company + 1000, 'Synthetic foreign revision tax');
        $foreign = (new \Modules\Nfse\Support\TaxFiscalAssociationStore())->confirm(
            $company + 1000, $foreignTax,
            \Modules\Nfse\Contracts\FiscalTaxIdentity::PIS,
            \Modules\Nfse\Contracts\FiscalTaxRole::PROVIDER_DUE,
            '2026-01-01', null, 'synthetic-foreign-evidence', 1,
        );
        $this->patchJson(
            route('nfse.settings.tax-associations.revise', ['associationId' => $foreign->id]),
            $payload,
        )->assertStatus(409)->assertDontSee('Synthetic foreign revision tax');

        self::assertNull($original->fresh()->effective_until);
        self::assertNull($foreign->fresh()->effective_until);
        self::assertSame(1, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
    }

    public function testReadOnlyOperatorCanInspectButCannotReviseFiscalAssociations(): void
    {
        $this->withExceptionHandling()->loginAs();
        $company = (int) company_id();
        $tax = $this->tax($company, 'Synthetic readonly tax');
        $this->postJson(route('nfse.settings.tax-associations.store'), $this->confirmation($tax))
            ->assertCreated();
        $original = TaxFiscalAssociation::query()->where('tax_id', $tax)->firstOrFail();

        $role = $this->createRole('nfse-tax-association-readonly');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-nfse-settings');
        $reader = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$company],
            'roles' => [$role->id],
        ])));
        $this->loginAs($reader);

        $this->getJson(route('nfse.settings.tax-associations.resources'))->assertOk();
        $payload = $this->confirmation($tax);
        $payload['effective_from'] = now()->addDays(2)->toDateString();
        $this->patchJson(
            route('nfse.settings.tax-associations.revise', ['associationId' => $original->id]),
            $payload,
        )->assertForbidden();

        self::assertNull($original->fresh()->effective_until);
        self::assertSame(1, TaxFiscalAssociation::query()->where('tax_id', $tax)->count());
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
