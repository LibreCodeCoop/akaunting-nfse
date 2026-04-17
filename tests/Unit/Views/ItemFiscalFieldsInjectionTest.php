<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views;

use Modules\Nfse\Tests\TestCase;

final class ItemFiscalFieldsInjectionTest extends TestCase
{
    public function testNativeItemCreateViewIncludesNfseFiscalFieldsPartial(): void
    {
        $createPath = dirname(__DIR__, 5) . '/resources/views/common/items/create.blade.php';
        $content = (string) file_get_contents($createPath);

        self::assertStringContainsString("@includeIf('nfse::items.partials.fiscal-fields')", $content);
    }

    public function testNativeItemEditViewIncludesNfseFiscalFieldsPartial(): void
    {
        $editPath = dirname(__DIR__, 5) . '/resources/views/common/items/edit.blade.php';
        $content = (string) file_get_contents($editPath);

        self::assertStringContainsString("@includeIf('nfse::items.partials.fiscal-fields')", $content);
    }

    public function testNfseItemFiscalPartialContainsLc116AndNbsFields(): void
    {
        $partialPath = dirname(__DIR__, 3) . '/Resources/views/items/partials/fiscal-fields.blade.php';
        $content = (string) file_get_contents($partialPath);

        self::assertStringContainsString('name="nfse_item_lista_servico"', $content);
        self::assertStringContainsString('name="nfse_codigo_tributacao_nacional"', $content);
    }
}
