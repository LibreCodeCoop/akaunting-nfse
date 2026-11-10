<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\CorrectedE0015OperationKey;
use Modules\Nfse\Application\DpsOperationKey;
use Modules\Nfse\Application\FiscalIssuanceBlocked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorrectedE0015OperationKeyTest extends TestCase
{
    private const BASE = '0d4d746867f35db1228d2eafc5f5299a95b848d3e03d76d9a61b57c1c2749cb8';

    public function testCorrectedCompetenceCreatesOneStableSuccessor(): void
    {
        $attempts = [self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')];
        $resolve = fn (): string => $this->resolve($attempts, '2026-11-10', '2026-11-10');
        $successor = $resolve();

        self::assertNotSame(self::BASE, $successor);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $successor);
        self::assertSame($successor, $resolve(), 'The same corrected operation must not allocate again.');

        $attempts[$successor] = self::attempt(22, 'pending', null, null, '2026-11-10');
        self::assertSame($successor, $resolve(), 'A pending successor stays pinned for recovery.');
    }

    public function testSecondRejectionWithoutAnotherCorrectionNeverGeneratesAThirdNumber(): void
    {
        $attempts = [self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')];
        $secondKey = $this->resolve($attempts, '2026-11-10', '2026-11-10');
        $attempts[$secondKey] = self::attempt(22, 'rejected', 'E0015', 422, '2026-11-10');

        $this->expectException(FiscalIssuanceBlocked::class);
        $this->resolve($attempts, '2026-11-10', '2026-11-10');
    }

    public function testOmittedCompetenceCorrectsRejectedFutureDateUsingIssueDate(): void
    {
        $attempts = [self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')];

        self::assertSame(
            $this->resolve($attempts, '2026-11-10', '2026-11-10'),
            $this->resolve($attempts, '', '2026-11-10'),
        );
    }

    public function testCalendarDayAdvancingCanCorrectPreviouslyFutureCompetence(): void
    {
        $attempts = [self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')];

        self::assertNotSame(self::BASE, $this->resolve($attempts, '2026-11-11', '2026-11-11'));
    }

    public function testUncorrectedFutureCompetenceShowsActualDatesWithoutAllocating(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('11/11/2026');

        $this->resolve([self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')], '2026-11-11', '2026-11-10');
    }

    public function testUnchangedCompetenceWithSameDayRejectionDoesNotRepeatPost(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('Nenhuma nova DPS');

        $this->resolve([self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-10')], '2026-11-10', '2026-11-10');
    }

    #[DataProvider('unsafeOutcomes')]
    public function testOtherOutcomesNeverAuthorizeAnIndependentOperation(string $status, ?string $code, ?int $http): void
    {
        $attempts = [self::BASE => self::attempt(21, $status, $code, $http, '2026-11-11')];
        self::assertSame(self::BASE, $this->resolve($attempts, '2026-11-10', '2026-11-10'));
    }

    /** @return array<string, array{string,?string,?int}> */
    public static function unsafeOutcomes(): array
    {
        return [
            'unconfirmed' => ['rejected', 'E0015', null],
            'server error' => ['rejected', 'E0015', 503],
            'different code' => ['rejected', 'E0312', 400],
            'pending' => ['pending', null, null],
            'ambiguous' => ['ambiguous', null, null],
            'authorized' => ['authorized', null, null],
            'technical' => ['technical_failure', null, null],
        ];
    }

    public function testKeysRemainScopedToOriginalCompanyInvoiceAndGroup(): void
    {
        $attempts = [self::BASE => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')];
        $other = DpsOperationKey::forInvoice(20, 199, 1, 'different-group');
        self::assertNotSame(
            $this->resolve($attempts, '2026-11-10', '2026-11-10'),
            $this->resolve([$other => self::attempt(21, 'rejected', 'E0015', 400, '2026-11-11')], '2026-11-10', '2026-11-10', $other),
        );
    }

    private function resolve(array $attempts, string $competence, string $today, string $base = self::BASE): string
    {
        return (new CorrectedE0015OperationKey())->resolve(
            $base,
            $competence,
            $today,
            static fn (string $key): ?array => $attempts[$key] ?? null,
        );
    }

    /** @return array{id:int,status:string,official_code:?string,http_status:?int,competence_date:string,started_at:string} */
    private static function attempt(int $id, string $status, ?string $code, ?int $http, string $competence): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'official_code' => $code,
            'http_status' => $http,
            'competence_date' => $competence,
            'started_at' => '2026-11-10 17:51:11',
        ];
    }
}
