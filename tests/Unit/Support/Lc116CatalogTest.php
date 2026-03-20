<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Tests\TestCase;

class Lc116CatalogTest extends TestCase
{
    public function testSearchReturnsKnownServiceWithFormattedLabel(): void
    {
        $catalog = new Lc116Catalog();

        $result = $catalog->search('1.07');

        self::assertNotEmpty($result);
        self::assertSame('0107', $result[0]['code']);
        self::assertSame('1.07', $result[0]['display_code']);
        self::assertStringContainsString('Suporte tecnico em informatica', $result[0]['label']);
    }

    public function testSearchCanFindByDescriptionText(): void
    {
        $catalog = new Lc116Catalog();

        $result = $catalog->search('contabilidade');

        self::assertNotEmpty($result);
        self::assertSame('1719', $result[0]['code']);
    }

    public function testSearchRespectsLimit(): void
    {
        $catalog = new Lc116Catalog();

        $result = $catalog->search('', 3);

        self::assertCount(3, $result);
    }
}
