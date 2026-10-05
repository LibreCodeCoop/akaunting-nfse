<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Jobs;

use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Jobs\ProcessBulkEmissionUnit;
use PHPUnit\Framework\TestCase;

final class ProcessBulkEmissionUnitTest extends TestCase
{
    public function testJobProcessesExactlyItsPersistedUnit(): void
    {
        $processor = $this->createMock(BulkEmissionUnitProcessor::class);
        $processor->expects(self::once())
            ->method('process')
            ->with(42);

        (new ProcessBulkEmissionUnit(42))->handle($processor);
    }

    public function testJobDoesNotRequestFrameworkLevelAutomaticRetries(): void
    {
        self::assertSame(1, (new ProcessBulkEmissionUnit(42))->tries);
    }
}
