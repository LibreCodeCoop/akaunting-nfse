<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views {
    use Modules\Nfse\Tests\TestCase;

    final class OperationalViewsExistenceTest extends TestCase
    {
        public function testOperationalScreensExistInViewsDirectory(): void
        {
            $basePath = dirname(__DIR__, 3) . '/Resources/views';

            self::assertFileExists($basePath . '/dashboard/index.blade.php');
            self::assertFileExists($basePath . '/invoices/index.blade.php');
            self::assertFileExists($basePath . '/invoices/show.blade.php');
        }
    }
}
