<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Controllers;

use Modules\Nfse\Http\Controllers\Controller;
use Modules\Nfse\Tests\TestCase;

final class ControllerAclOverrideTest extends TestCase
{
    public function testModuleBaseControllerOwnsPublicVoidPermissionOverride(): void
    {
        self::assertTrue(
            is_subclass_of(Controller::class, \App\Abstracts\Http\Controller::class),
        );

        $method = new \ReflectionMethod(Controller::class, 'assignPermissionsToController');

        self::assertSame(Controller::class, $method->getDeclaringClass()->getName());
        self::assertTrue($method->isPublic());
        self::assertSame('void', (string) $method->getReturnType());
    }
}
