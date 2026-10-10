<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\InvoiceItemDescription;
use PHPUnit\Framework\TestCase;

final class InvoiceItemDescriptionTest extends TestCase
{
    public function testUsesInvoiceLineDescriptionInsteadOfCatalogName(): void
    {
        self::assertSame(
            "Serviço de exemplo\nCentro de custo: TESTE-001",
            InvoiceItemDescription::fromItem([
                'name' => 'Nome curto',
                'description' => 'Serviço de exemplo\nCentro de custo: TESTE-001',
            ]),
        );
    }

    public function testFallsBackToNameWhenLineDescriptionIsEmpty(): void
    {
        self::assertSame('Nome curto', InvoiceItemDescription::fromItem([
            'name' => 'Nome curto',
            'description' => ' ',
        ]));
    }
}
