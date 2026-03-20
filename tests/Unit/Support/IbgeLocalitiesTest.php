<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Tests\TestCase;

class IbgeLocalitiesTest extends TestCase
{
    public function testMapUfsFiltersInvalidRowsAndSortsByName(): void
    {
        $mapper = new IbgeLocalities();

        $result = $mapper->mapUfs([
            ['sigla' => 'RJ', 'nome' => 'Rio de Janeiro'],
            ['sigla' => 'SP', 'nome' => 'Sao Paulo'],
            ['sigla' => 'X', 'nome' => 'Invalid'],
            ['sigla' => 'MG'],
        ]);

        self::assertSame([
            ['uf' => 'RJ', 'name' => 'Rio de Janeiro'],
            ['uf' => 'SP', 'name' => 'Sao Paulo'],
        ], $result);
    }

    public function testMapMunicipalitiesFiltersInvalidRowsAndSortsByName(): void
    {
        $mapper = new IbgeLocalities();

        $result = $mapper->mapMunicipalities([
            ['id' => 3304557, 'nome' => 'Rio de Janeiro'],
            ['id' => 3550308, 'nome' => 'Sao Paulo'],
            ['id' => null, 'nome' => 'Invalid'],
            ['id' => 123, 'nome' => ''],
        ]);

        self::assertSame([
            ['ibge_code' => '3304557', 'name' => 'Rio de Janeiro'],
            ['ibge_code' => '3550308', 'name' => 'Sao Paulo'],
        ], $result);
    }
}
