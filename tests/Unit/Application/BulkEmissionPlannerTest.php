<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\BulkEmissionPlanner;
use PHPUnit\Framework\TestCase;

final class BulkEmissionPlannerTest extends TestCase
{
    public function testPlansOnlySingleResolvedPendingFiscalUnit(): void
    {
        $result = (new BulkEmissionPlanner())->plan([
            [
                'invoice_id' => 10,
                'ready' => true,
                'groups' => [
                    ['key' => 'group-a', 'issued' => false],
                ],
            ],
            [
                'invoice_id' => 11,
                'ready' => false,
                'blockers' => ['invalid_profile'],
                'groups' => [
                    ['key' => 'group-b', 'issued' => false],
                ],
            ],
            [
                'invoice_id' => 12,
                'ready' => true,
                'groups' => [
                    ['key' => 'group-c', 'issued' => false],
                    ['key' => 'group-d', 'issued' => false],
                ],
            ],
            [
                'invoice_id' => 13,
                'ready' => true,
                'groups' => [
                    ['key' => 'group-e', 'issued' => true],
                ],
            ],
        ]);

        self::assertSame([
            ['invoice_id' => 10, 'emission_group_key' => 'group-a'],
        ], $result['queued']);

        self::assertSame(11, $result['blocked'][0]['invoice_id']);
        self::assertSame('readiness', $result['blocked'][0]['reason']);
        self::assertSame(['invalid_profile'], $result['blocked'][0]['details']);

        self::assertSame(12, $result['blocked'][1]['invoice_id']);
        self::assertSame('requires_group_selection', $result['blocked'][1]['reason']);
        self::assertSame(['group-c', 'group-d'], $result['blocked'][1]['details']);

        self::assertSame([13], $result['already_issued']);
    }

    public function testPreviouslyIssuedGroupsDoNotForceInteractiveSelection(): void
    {
        $result = (new BulkEmissionPlanner())->plan([
            [
                'invoice_id' => 20,
                'ready' => true,
                'groups' => [
                    ['key' => 'old-group', 'issued' => true],
                    ['key' => 'remaining-group', 'issued' => false],
                ],
            ],
        ]);

        self::assertSame([
            ['invoice_id' => 20, 'emission_group_key' => 'remaining-group'],
        ], $result['queued']);
        self::assertSame([], $result['blocked']);
    }
}
