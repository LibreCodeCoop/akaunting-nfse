<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use PHPUnit\Framework\TestCase;

final class BulkEmissionStatusPolicyTest extends TestCase
{
    public function testQueuedUnitCanStartOrFinishWithoutBlindRetry(): void
    {
        $policy = new BulkEmissionStatusPolicy();

        self::assertTrue($policy->canTransition('queued', 'processing'));
        self::assertTrue($policy->canTransition('queued', 'blocked'));
        self::assertTrue($policy->canTransition('queued', 'issued'));
        self::assertFalse($policy->canTransition('queued', 'rejected'));
    }

    public function testRetryableReadErrorCanResumeThroughRecoveryPath(): void
    {
        $policy = new BulkEmissionStatusPolicy();

        self::assertTrue($policy->canTransition('retryable_read_error', 'processing'));
        self::assertTrue($policy->canTransition('retryable_read_error', 'issued'));
        self::assertFalse($policy->canTransition('retryable_read_error', 'blocked'));
    }

    public function testTerminalUnitCannotBeRequeued(): void
    {
        $policy = new BulkEmissionStatusPolicy();

        foreach (['issued', 'blocked', 'rejected'] as $terminal) {
            self::assertTrue($policy->isTerminal($terminal));
            self::assertSame([], $policy->allowedTargets($terminal));
            self::assertFalse($policy->canTransition($terminal, 'processing'));
        }
    }

    public function testAggregatePreservesActionablePartialFailureClass(): void
    {
        $policy = new BulkEmissionStatusPolicy();

        self::assertSame('processing', $policy->aggregate(['issued', 'processing']));
        self::assertSame('queued', $policy->aggregate(['issued', 'queued']));
        self::assertSame('partial_retryable', $policy->aggregate(['issued', 'retryable_read_error']));
        self::assertSame('partial_rejected', $policy->aggregate(['issued', 'rejected']));
        self::assertSame('partial_blocked', $policy->aggregate(['issued', 'blocked']));
        self::assertSame('issued', $policy->aggregate(['issued', 'issued']));
    }
}
