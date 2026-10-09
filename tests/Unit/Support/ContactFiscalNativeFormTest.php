<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

final class ContactFiscalNativeFormTest extends TestCase
{
    public function testNativeContactAddressKeepsCoreFieldsAndAddsCustomerFiscalFields(): void
    {
        $path = dirname(__DIR__, 3) . '/Resources/overrides/components/contacts/form/address.blade.php';
        $view = file_get_contents($path);
        self::assertIsString($view);
        foreach (['name="address"', 'name="city"', 'name="zip_code"', 'name="state"', 'name="nfse_municipal_registration"', 'name="nfse_legal_name"'] as $field) {
            self::assertStringContainsString($field, $view);
        }
        self::assertStringContainsString("@if (\$type === 'customer')", $view);
    }
}
