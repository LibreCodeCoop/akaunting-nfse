<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NbsEmissionReadiness;
use PHPUnit\Framework\TestCase;

final class NbsEmissionReadinessTest extends TestCase
{
    public function testIbsCbsRequiresOfficialNbs(): void
    {
        $check = new NbsEmissionReadiness();
        self::assertFalse($check->valid('', true));
        self::assertFalse($check->valid('11501100', true));
        self::assertFalse($check->valid('123456789', true));
        self::assertTrue($check->valid('115011000', true));
    }

    public function testLegacyIssOnlyDpsDoesNotRequireNbs(): void
    {
        self::assertTrue((new NbsEmissionReadiness())->valid('', false));
    }
}
