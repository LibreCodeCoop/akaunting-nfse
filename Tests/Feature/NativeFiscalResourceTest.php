<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Api\ResourceShowing;
use App\Http\Resources\Common\Contact as ContactResource;
use App\Http\Resources\Common\Item as ItemResource;
use App\Jobs\Auth\CreateUser;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Traits\Permissions;
use Modules\Nfse\Listeners\AppendFiscalProfileToApiResource;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\ContactFiscalProfileStore;
use Tests\Feature\FeatureTestCase;

final class NativeFiscalResourceTest extends FeatureTestCase
{
    use Permissions;

    public function testNativeItemResourceReturnsAuthorizedFiscalProfile(): void
    {
        $item = Item::factory()->enabled()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $this->company->id,
            'item_id' => (int) $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $this->loginAs();
        $payload = (new ItemResource($item))->toArray(request());

        self::assertSame('0107', $payload['nfse_fiscal_profile']['item_lista_servico']);
        self::assertSame('010701', $payload['nfse_fiscal_profile']['codigo_tributacao_nacional']);
        self::assertSame((int) $this->company->id, (int) $payload['company_id']);
    }

    public function testNativeCustomerResourceReturnsAuthorizedFiscalProfile(): void
    {
        $contact = Contact::factory()->customer()->enabled()->create();
        (new ContactFiscalProfileStore())->persist(
            (int) $this->company->id,
            (int) $contact->id,
            ['municipal_registration' => '123456', 'legal_name' => 'Synthetic Customer Ltd'],
        );

        $this->loginAs();
        $payload = (new ContactResource($contact))->toArray(request());

        self::assertSame('123456', $payload['nfse_fiscal_profile']['municipal_registration']);
        self::assertSame('Synthetic Customer Ltd', $payload['nfse_fiscal_profile']['legal_name']);
    }

    public function testNativeResourcesDoNotExposeFiscalDataWithoutFiscalReadPermission(): void
    {
        $item = Item::factory()->enabled()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $this->company->id,
            'item_id' => (int) $item->id,
            'codigo_tributacao_nacional' => '010701',
        ]);
        $contact = Contact::factory()->customer()->enabled()->create();
        (new ContactFiscalProfileStore())->persist(
            (int) $this->company->id,
            (int) $contact->id,
            ['municipal_registration' => '123456', 'legal_name' => 'Protected Legal Name'],
        );

        $this->loginAs();
        $role = $this->createRole('nfse-core-resource-only');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-items');
        $this->attachPermission($role, 'read-contacts');
        $operator = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($operator);
        $itemData = (new ItemResource($item))->toArray(request());
        $contactData = (new ContactResource($contact))->toArray(request());

        self::assertArrayNotHasKey('nfse_fiscal_profile', $itemData);
        self::assertArrayNotHasKey('nfse_fiscal_profile', $contactData);
    }

    public function testForeignCompanyResourceCannotExposeFiscalData(): void
    {
        $this->loginAs();
        $item = new Item();
        $item->id = 123;
        $item->company_id = (int) $this->company->id + 1000;
        $event = new ResourceShowing($item);

        (new AppendFiscalProfileToApiResource())->showing($event);

        self::assertSame([], $event->resources);
    }
}
