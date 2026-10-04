<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

final class CiPerformanceBudgetTest extends TestCase
{
    /**
     * @dataProvider requiredWorkflowBudgets
     */
    public function testExpensiveCiJobsDeclareReviewableTimeoutBudget(string $workflow, int $minutes): void
    {
        $path = dirname(__DIR__, 3) . '/.github/workflows/' . $workflow;
        $content = file_get_contents($path);

        self::assertIsString($content);
        self::assertStringContainsString(
            'timeout-minutes: ' . $minutes,
            $content,
            $workflow . ' must keep an explicit CI duration budget.',
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function requiredWorkflowBudgets(): iterable
    {
        yield 'PHPUnit' => ['phpunit.yml', 15];
        yield 'Akaunting Feature' => ['akaunting-feature.yml', 20];
        yield 'Psalm' => ['psalm.yml', 15];
        yield 'Playwright Smoke' => ['playwright-smoke.yml', 30];
        yield 'Playwright Full UI' => ['playwright-full-ui.yml', 30];
    }
}
