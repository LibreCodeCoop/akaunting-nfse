<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Tests\TestCase;

final class FiscalClientCompositionTest extends TestCase
{
    public function testControllersDoNotConstructProtocolClientsDirectly(): void
    {
        foreach ([
            'InvoiceController.php',
            'AdnController.php',
            'SettingsController.php',
        ] as $controller) {
            $content = (string) file_get_contents(
                dirname(__DIR__, 3) . '/Http/Controllers/' . $controller,
            );

            self::assertStringNotContainsString('new NfseClient(', $content, $controller);
            self::assertStringNotContainsString('new AdnClient(', $content, $controller);
            self::assertStringNotContainsString('new MunicipalParametersClient(', $content, $controller);
        }
    }
}
