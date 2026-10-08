<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Tests\Feature\FeatureTestCase;

final class NationalTaxCatalogSearchTest extends FeatureTestCase
{
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

    public function testCatalogSearchRequiresAQueryOrLc116Selection(): void
    {
        $this->loginAs()->getJson(route('nfse.national-services'))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
