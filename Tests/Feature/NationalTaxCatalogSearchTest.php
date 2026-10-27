<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Traits\Permissions;
use Tests\Feature\FeatureTestCase;

final class NationalTaxCatalogSearchTest extends FeatureTestCase
{
    use Permissions;

    public function testBrowserCatalogDeniesAdminWithoutFiscalReadPermission(): void
    {
        $this->loginAs();
        $role = $this->createRole('nfse-catalog-no-fiscal-read');
        $this->attachPermission($role, 'read-admin-panel');

        $user = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($user);
        $this->getJson(route('nfse.national-services', ['lc116' => '0101']))
            ->assertForbidden();
    }

    public function testCatalogSearchReturnsNationalCodesRelatedToLc116(): void
    {
        $response = $this->loginAs()->getJson(route('nfse.national-services', ['lc116' => '0101']));
        $response->assertOk()->assertJsonStructure(['data' => [['code', 'description']]]);

        foreach ($response->json('data') as $entry) {
            self::assertStringStartsWith('0101', $entry['code']);
            self::assertSame(6, strlen($entry['code']));
        }
    }

    public function testCatalogSearchRejectsLongQueries(): void
    {
        $this->loginAs()
            ->getJson(route('nfse.national-services', ['q' => str_repeat('a', 101)]))
            ->assertStatus(422);
    }

    public function testCatalogSearchDefaultsToFiftyOfficialCodesWithoutFilters(): void
    {
        $response = $this->loginAs()->getJson(route('nfse.national-services'));

        $response->assertOk()->assertJsonStructure(['data' => [['code', 'description']]]);
        $entries = $response->json('data');
        self::assertCount(50, $entries);
        self::assertSame('010101', $entries[0]['code']);
    }

    public function testCatalogSearchSupportsBoundedCompleteCatalogForFrontend(): void
    {
        $response = $this->loginAs()->getJson(route('nfse.national-services', ['limit' => 500]));

        $response->assertOk()->assertJsonStructure(['data' => [['code', 'description']]]);
        $entries = $response->json('data');
        self::assertGreaterThan(50, count($entries));
        self::assertLessThanOrEqual(500, count($entries));
    }
}
