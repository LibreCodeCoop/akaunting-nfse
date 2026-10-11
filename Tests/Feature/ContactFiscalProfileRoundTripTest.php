<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Contact;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

final class ContactFiscalProfileRoundTripTest extends FeatureTestCase
{
    public function testNativeCustomerCreateEditAndReopenPreserveFiscalNameAndMunicipalRegistration(): void
    {
        $request = Contact::factory()->customer()->enabled()->raw();
        $request['nfse_legal_name'] = 'EMPRESA DE TESTE LTDA';
        $request['nfse_municipal_registration'] = '123456';

        $this->loginAs()
            ->post(route('customers.store'), $request)
            ->assertOk()
            ->assertJsonPath('success', true);

        $contact = Contact::query()->where('email', $request['email'])->firstOrFail();
        $this->assertDatabaseHas('nfse_contact_fiscal_profiles', [
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
            'legal_name' => 'EMPRESA DE TESTE LTDA',
            'municipal_registration' => '123456',
        ]);

        $this->get(route('customers.edit', $contact->id))
            ->assertOk()
            ->assertSee('name="nfse_legal_name"', false)
            ->assertSee('value="EMPRESA DE TESTE LTDA"', false)
            ->assertSee('value="123456"', false);

        $request['nfse_legal_name'] = 'EMPRESA ATUALIZADA LTDA';
        $request['nfse_municipal_registration'] = '987654';

        $this->patch(route('customers.update', $contact->id), $request)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nfse_contact_fiscal_profiles', [
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
            'legal_name' => 'EMPRESA ATUALIZADA LTDA',
            'municipal_registration' => '987654',
        ]);
        $this->get(route('customers.edit', $contact->id))
            ->assertOk()
            ->assertSee('value="EMPRESA ATUALIZADA LTDA"', false)
            ->assertSee('value="987654"', false);
    }

    public function testCustomerFiscalRegistrationIsOptionalAndTenantScoped(): void
    {
        $request = Contact::factory()->customer()->enabled()->raw();
        $request['nfse_legal_name'] = 'EMPRESA SEM IM';
        $request['nfse_municipal_registration'] = '';

        $this->loginAs()
            ->post(route('customers.store'), $request)
            ->assertOk()
            ->assertJsonPath('success', true);

        $contact = Contact::query()->where('email', $request['email'])->firstOrFail();
        self::assertSame(
            'EMPRESA SEM IM',
            (string) DB::table('nfse_contact_fiscal_profiles')
                ->where('company_id', $contact->company_id)
                ->where('contact_id', $contact->id)
                ->value('legal_name'),
        );
        self::assertNull(DB::table('nfse_contact_fiscal_profiles')
            ->where('company_id', (int) $contact->company_id + 10000)
            ->where('contact_id', $contact->id)
            ->first());
    }
}
