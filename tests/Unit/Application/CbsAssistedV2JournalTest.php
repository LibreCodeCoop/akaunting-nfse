<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\CbsAssistedV2Journal;
use PHPUnit\Framework\TestCase;

final class CbsAssistedV2JournalTest extends TestCase
{
    public function testRepeatedBatchIsIdempotentAndNewerUpdateReplacesBalance(): void
    {
        $policy = new CbsAssistedV2Journal();
        $first = $this->batch($this->event('10.00', '2026-10-01T08:00:00Z'));
        $state = $policy->materialize([], $first);
        self::assertSame(1, $state['applied']);

        $again = $policy->materialize($state['observations'], $first);
        self::assertSame(0, $again['applied']);
        self::assertSame(1, $again['unchanged']);
        self::assertSame([], $again['conflicts']);

        $later = $policy->materialize($again['observations'], $this->batch(
            $this->event('7.00', '2026-10-02T08:00:00Z'),
        ));
        self::assertSame('7.00', $later['observations'][str_repeat('a', 64)]['official_reported_balance']);
        self::assertSame(1, $later['applied']);

        $stale = $policy->materialize($later['observations'], $first);
        self::assertSame(1, $stale['stale']);
        self::assertSame('7.00', $stale['observations'][str_repeat('a', 64)]['official_reported_balance']);
    }

    public function testEqualTimestampWithConflictingValueCannotSilentlyOverwrite(): void
    {
        $policy = new CbsAssistedV2Journal();
        $first = $policy->materialize([], $this->batch(
            $this->event('10.00', '2026-10-01T08:00:00Z'),
        ));
        $conflicting = $policy->materialize($first['observations'], $this->batch(
            $this->event('11.00', '2026-10-01T08:00:00Z'),
        ));
        self::assertSame(0, $conflicting['applied']);
        self::assertSame(1, count($conflicting['conflicts']));
        self::assertSame('10.00', $conflicting['observations'][str_repeat('a', 64)]['official_reported_balance']);
    }

    public function testMissingFiscalKeyRemainsUnresolvedRatherThanMergedByAmount(): void
    {
        $event = $this->event('10.00', '2026-10-01T08:00:00Z');
        $event['identity_key'] = null;
        $state = (new CbsAssistedV2Journal())->materialize([], $this->batch($event));

        self::assertSame([], $state['observations']);
        self::assertSame(1, count($state['unresolved']));
        self::assertSame(0, $state['applied']);
    }

    private function batch(array $event): array
    {
        return [
            'incremental' => true,
            'complete_period' => false,
            'source_sha256' => str_repeat('b', 64),
            'source_provenance' => 'not_authenticated_by_parser',
            'events' => [$event],
        ];
    }

    private function event(string $balance, string $timestamp): array
    {
        return [
            'identity_key' => str_repeat('a', 64),
            'updated_at' => $timestamp,
            'official_reported_balance' => $balance,
            'period' => '2026-10',
            'effect' => 'simulation_2026',
        ];
    }
}
