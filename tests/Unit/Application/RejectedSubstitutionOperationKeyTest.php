<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\DpsOperationKey;
use Modules\Nfse\Application\RejectedSubstitutionOperationKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectedSubstitutionOperationKeyTest extends TestCase
{
    private const COMPANY = 17;
    private const INVOICE = 420;
    private const RECEIPT = 30;

    public function testE1235CreatesAStableSuccessorInsteadOfReusingTheConsumedReservation(): void
    {
        $base = $this->operationKey();
        $latest = [
            $base => $this->attempt(15, 'rejected', 'E1235', 400),
        ];

        $resolve = fn (): string => $this->resolve($latest);

        $successor = $resolve();

        self::assertSame($this->operationKey(15), $successor);
        self::assertNotSame($base, $successor);
        self::assertSame($successor, $resolve(), 'Repeated requests must share one new operation.');
    }

    public function testASecondDefinitiveSchemaRejectionGetsAThirdDistinctOperation(): void
    {
        $latest = [
            $this->operationKey() => $this->attempt(15, 'rejected', 'E1235', 400),
            $this->operationKey(15) => $this->attempt(16, 'rejected', 'E1235', 422),
            $this->operationKey(16) => $this->attempt(17, 'pending'),
        ];

        self::assertSame($this->operationKey(16), $this->resolve($latest));
    }

    public function testE0713AfterE1235GetsOneNewKeyWithoutReusingEitherDps(): void
    {
        $base = $this->operationKey();
        $revisions = [
            $base => $this->attempt(15, 'rejected', 'E1235', 400),
            $this->operationKey(15) => $this->attempt(16, 'rejected', 'E0713', 400),
        ];

        $expected = $this->operationKey(16);
        self::assertSame($expected, $this->resolve($revisions));
        self::assertSame($expected, $this->resolve($revisions), 'Retries must reuse one successor key.');
        self::assertNotSame($this->operationKey(15), $expected);
    }

    public function testOfficialE0713CanStartASuccessorOnlyWithConfirmedRejection(): void
    {
        $base = $this->operationKey();
        self::assertSame(
            $this->operationKey(16),
            $this->resolve([$base => $this->attempt(16, 'rejected', 'E0713', 422)]),
        );
    }

    #[DataProvider('unsafePreviousOutcomes')]
    public function testNeverStartsAnotherOperationForAnUnconfirmedOrUnreviewedResult(
        string $status,
        ?string $code,
        ?int $http,
    ): void {
        $base = $this->operationKey();
        self::assertSame(
            $base,
            $this->resolve([$base => $this->attempt(15, $status, $code, $http)]),
        );
    }

    /** @return array<string, array{string, ?string, ?int}> */
    public static function unsafePreviousOutcomes(): array
    {
        return [
            'ambiguous transport' => ['ambiguous', null, null],
            'pending transmission' => ['pending', null, null],
            'authorized receipt' => ['authorized', null, null],
            'local technical failure' => ['technical_failure', null, null],
            'unconfirmed rejection' => ['rejected', 'E1235', null],
            'unconfirmed E0713' => ['rejected', 'E0713', null],
            'nonofficial E0713' => ['rejected', 'E0713', 500],
            'unreviewed tax failure' => ['rejected', 'E0724', 400],
            'municipal E0312' => ['rejected', 'E0312', 422],
            'nonofficial rejection' => ['rejected', 'E1235', 500],
        ];
    }

    public function testNoPreviousAttemptKeepsTheOriginalOperation(): void
    {
        self::assertSame($this->operationKey(), $this->resolve([]));
    }

    public function testRevisionKeysStayScopedToTheOriginalReceiptAndTenant(): void
    {
        self::assertNotSame(
            DpsOperationKey::forInvoice(17, 420, 2, substitutedReceiptId: 30, rejectedAttemptId: 15),
            DpsOperationKey::forInvoice(18, 420, 2, substitutedReceiptId: 30, rejectedAttemptId: 15),
        );
        self::assertNotSame(
            DpsOperationKey::forInvoice(17, 420, 2, substitutedReceiptId: 30, rejectedAttemptId: 15),
            DpsOperationKey::forInvoice(17, 420, 2, substitutedReceiptId: 31, rejectedAttemptId: 15),
        );
    }

    public function testCycleInRejectionHistoryIsRejected(): void
    {
        $base = $this->operationKey();
        $successor = $this->operationKey(15);
        $resolver = new RejectedSubstitutionOperationKey();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cycle');

        $resolver->resolve(
            $base,
            fn (string $key): array => $this->attempt(
                $key === $base ? 15 : 16,
                'rejected',
                'E1235',
                400,
            ),
            static fn (int $id): string => $id === 15 ? $successor : $base,
        );
    }

    /** @param array<string, array<string,mixed>> $attempts */
    private function resolve(array $attempts): string
    {
        return (new RejectedSubstitutionOperationKey())->resolve(
            $this->operationKey(),
            static fn (string $key): ?array => $attempts[$key] ?? null,
            fn (int $attemptId): string => $this->operationKey($attemptId),
        );
    }

    /** @return array{id:int,status:string,official_code:?string,http_status:?int} */
    private function attempt(int $id, string $status, ?string $code = null, ?int $http = null): array
    {
        return ['id' => $id, 'status' => $status, 'official_code' => $code, 'http_status' => $http];
    }

    private function operationKey(?int $rejectedId = null): string
    {
        return DpsOperationKey::forInvoice(
            self::COMPANY,
            self::INVOICE,
            2,
            substitutedReceiptId: self::RECEIPT,
            rejectedAttemptId: $rejectedId,
        );
    }
}
