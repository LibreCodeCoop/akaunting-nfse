<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\BrazilianStates;
use Modules\Nfse\Tests\TestCase;

final class BrazilianStatesTest extends TestCase
{
    public function testAllReturnsFullBrazilianStatesCatalogInStableOrder(): void
    {
        $catalog = (new BrazilianStates())->all();

        self::assertCount(27, $catalog);
        self::assertSame(['uf' => 'AC', 'name' => 'Acre'], $catalog[0]);
        self::assertSame(['uf' => 'RJ', 'name' => 'Rio de Janeiro'], $catalog[18]);
        self::assertSame(['uf' => 'TO', 'name' => 'Tocantins'], $catalog[26]);
    }
}
