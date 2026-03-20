<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Controllers;

use Modules\Nfse\Tests\TestCase;

class ControllerAclOverrideTest extends TestCase
{
    public function testAssignPermissionsToControllerIsOverriddenInModuleBaseController(): void
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/Http/Controllers/Controller.php');

        self::assertStringContainsString('class Controller extends \\App\\Abstracts\\Http\\Controller', $content);
        self::assertStringContainsString('public function assignPermissionsToController(): void', $content);
    }
}
