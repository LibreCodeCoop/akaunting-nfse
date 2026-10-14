<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\DpsFiscalIssuer;
use Modules\Nfse\Application\DpsOperationKey;
use Modules\Nfse\Application\DpsReservationAuthorizer;
use Modules\Nfse\Application\DpsReservationService;
use Modules\Nfse\Application\DpsSeriesCoordinator;
use Tests\Feature\FeatureTestCase;

/**
 * Persistent Akaunting feature tests only; no SEFIN/ADN transport is used.
 * Multi-connection concurrency remains a separate MySQL integration gate.
 */
final class DpsReservationServiceTest extends FeatureTestCase
{
    public function testSameLogicalOperationReusesReservationAcrossOrigins(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);

        $key = DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2, 'group-a');
        $service = $this->service();
        $first = $service->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'manual_group');
        $repeated = $service->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '00001', $key, 'automatic');

        self::assertSame($first, $repeated);
        self::assertSame('00001', $first['series']);
        self::assertSame('1', $first['number']);
        self::assertSame(1, DB::table('nfse_dps_reservations')->count());
        self::assertSame(1, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testDistinctFiscalUnitsAdvanceOneSharedIssuerCounter(): void
    {
        $this->loginAs();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        $a = $this->invoice();
        $b = $this->invoice();
        $service = $this->service();

        $first = $service->reserve((int) $a->company_id, (int) $a->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $a->company_id, (int) $a->id, 2), 'manual');
        $second = $service->reserve((int) $b->company_id, (int) $b->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $b->company_id, (int) $b->id, 2), 'automatic');

        self::assertSame('1', $first['number']);
        self::assertSame('2', $second['number']);
        self::assertNotSame($first['reservation_id'], $second['reservation_id']);
    }

    public function testSameInvoiceInBothEnvironmentsReservesIndependentNumbers(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer, 2);
        $this->activateSyntheticSeries($issuer, 1);
        $service = $this->service();

        $restricted = $service->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2),
            'manual',
        );
        $production = $service->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 1, '1',
            DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 1),
            'manual',
        );

        self::assertSame('1', $restricted['number']);
        self::assertSame('1', $production['number']);
        self::assertNotSame($restricted['reservation_id'], $production['reservation_id']);
        self::assertSame(2, DB::table('nfse_dps_reservations')->count());
    }

    public function testMissingActivationAndForgedOwnerFailBeforeMutation(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $key = DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2);
        $service = $this->service();

        try {
            $service->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'manual');
            self::fail('Unproven fiscal series should remain blocked.');
        } catch (\LogicException) {
        }

        try {
            $service->reserve((int) $invoice->company_id + 7, (int) $invoice->id, $issuer, 2, '1', $key, 'manual');
            self::fail('Forged tenant owner must be rejected.');
        } catch (AuthorizationException) {
        }

        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
    }

    public function testForeignFiscalIssuerCannotConsumeAnotherIssuersSequence(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $foreignIssuer = new DpsFiscalIssuer('3303302', 2, '33444555000199');
        // Even when another company provisioned this series locally, the
        // current company's invoice does not authorize its fiscal identity.
        $this->activateSyntheticSeries($foreignIssuer);

        try {
            $this->service()->reserve(
                (int) $invoice->company_id,
                (int) $invoice->id,
                $foreignIssuer,
                2,
                '1',
                DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2),
                'manual',
            );
            self::fail('An unrelated company may not reserve a foreign fiscal identity.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Fiscal issuer is not authorized for the company.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testUnapprovedSeriesFailsEvenIfItsCounterIsActive(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer, 2, '00002');

        try {
            $this->service()->reserve(
                (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '2',
                DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2),
                'manual',
            );
            self::fail('An active counter cannot authorize an unapproved fiscal series.');
        } catch (AuthorizationException) {
        }

        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testEnvironmentMustBeAuthorizedSeparatelyFromIssuer(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer, 1);

        try {
            // Test-only authorizer grants restricted issuance, not production.
            $this->service(2)->reserve(
                (int) $invoice->company_id, (int) $invoice->id, $issuer, 1, '1',
                DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 1),
                'automatic',
            );
            self::fail('An authorized fiscal issuer does not imply production permission.');
        } catch (AuthorizationException) {
        }

        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testReservationRejectsContextMutationAfterAllocation(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        $key = DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2);
        $service = $this->service();
        $service->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'manual');

        $this->expectException(\LogicException::class);
        $service->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 1, '1', $key, 'manual');
    }

    public function testExhaustedSequenceFailsWithoutWrapping(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        DB::table('nfse_dps_series')->update(['last_number' => 999999999999999]);

        $this->expectException(\OverflowException::class);
        ($this->service())->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2), 'manual',
        );
    }

    public function testReusingExistingReservationAfterSuspensionDoesNotAdvanceCounter(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        $key = DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2);
        $service = $this->service();

        $original = $service->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'manual',
        );
        DB::table('nfse_dps_series')->update(['status' => 'suspended']);
        $repeated = $service->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '00001', $key, 'automatic',
        );

        self::assertSame($original, $repeated);
        $other = $this->invoice();

        try {
            $service->reserve(
                (int) $other->company_id, (int) $other->id, $issuer, 2, '1',
                DpsOperationKey::forInvoice((int) $other->company_id, (int) $other->id, 2),
                'manual',
            );
            self::fail('Suspended series must not issue new reservations.');
        } catch (\LogicException) {
        }

        self::assertSame(1, DB::table('nfse_dps_reservations')->count());
        self::assertSame(1, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testDuplicateAllocationConflictRollsBackCounterUpdate(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        $service = $this->service();

        $service->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2),
            'manual',
        );

        // Synthetic stale counter: a restored local sequence must not
        // silently reuse an already allocated national number.
        DB::table('nfse_dps_series')->update(['last_number' => 0]);
        $other = $this->invoice();

        try {
            $service->reserve(
                (int) $other->company_id, (int) $other->id, $issuer, 2, '1',
                DpsOperationKey::forInvoice((int) $other->company_id, (int) $other->id, 2),
                'automatic',
            );
            self::fail('Duplicate fiscal number must be rejected by storage.');
        } catch (\Illuminate\Database\QueryException) {
        }

        self::assertSame(1, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testLastValidNumberIsAllocatedOnceAndNextOverflows(): void
    {
        $this->loginAs();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        DB::table('nfse_dps_series')->update(['last_number' => 999999999999998]);
        $service = $this->service();
        $first = $this->invoice();

        $reserved = $service->reserve(
            (int) $first->company_id, (int) $first->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $first->company_id, (int) $first->id, 2),
            'manual',
        );
        self::assertSame('999999999999999', $reserved['number']);

        $second = $this->invoice();
        try {
            $service->reserve(
                (int) $second->company_id, (int) $second->id, $issuer, 2, '1',
                DpsOperationKey::forInvoice((int) $second->company_id, (int) $second->id, 2),
                'manual',
            );
            self::fail('A fifteen-digit DPS sequence must not wrap.');
        } catch (\OverflowException) {
        }

        self::assertSame(1, DB::table('nfse_dps_reservations')->count());
        self::assertSame(999999999999999, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testUnimplementedExternalCoordinationModeCannotAllocate(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);

        DB::table('nfse_dps_series')->update([
            'coordination_mode' => 'external',
            'coordination_reference' => 'unverified-synthetic-coordinator',
        ]);
        try {
            $this->service()->reserve(
                (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
                DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2),
                'manual',
            );
            self::fail('An external coordinator must not be simulated by a text reference.');
        } catch (\LogicException) {
        }

        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testUnverifiedActiveRowCannotAllocateEvenWithCoordinationReference(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        try {
            $this->service(null, false)->reserve(
                (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
                DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2), 'manual',
            );
            self::fail('Local text is not external evidence.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('independently verified', $exception->getMessage());
        }
        self::assertSame(0, DB::table('nfse_dps_reservations')->count());
        self::assertSame(0, (int) DB::table('nfse_dps_series')->value('last_number'));
    }

    public function testLostCoordinationCannotReplayExistingReservationViaAllocator(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        $key = DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2);
        $this->service()->reserve((int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'manual');
        $this->expectException(\LogicException::class);
        $this->service(null, false)->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1', $key, 'automatic',
        );
    }

    public function testUnverifiedReferenceChangeFailsClosed(): void
    {
        $this->loginAs();
        $invoice = $this->invoice();
        $issuer = $this->issuer();
        $this->activateSyntheticSeries($issuer);
        DB::table('nfse_dps_series')->update(['coordination_reference' => 'unverified-other-installation']);
        $this->expectException(\LogicException::class);
        $this->service()->reserve(
            (int) $invoice->company_id, (int) $invoice->id, $issuer, 2, '1',
            DpsOperationKey::forInvoice((int) $invoice->company_id, (int) $invoice->id, 2), 'manual',
        );
    }

    private function service(?int $onlyEnvironment = null, bool $withSyntheticProof = true): DpsReservationService
    {
        // Synthetic fake only. The production authorization adapter belongs
        // to the verified multi-company profile/context contract (#417/#421).
        $authorizer = new class ($onlyEnvironment) implements DpsReservationAuthorizer {
            public function __construct(private readonly ?int $onlyEnvironment)
            {
            }

            public function assertCanReserve(
                int $companyId, int $invoiceId, DpsFiscalIssuer $issuer, int $environment, string $series,
            ): void {
                if ((int) company_id() !== $companyId
                    || $issuer->registration !== '11222333000181'
                    || $series !== '00001'
                    || ($this->onlyEnvironment !== null && $environment !== $this->onlyEnvironment)) {
                    throw new AuthorizationException('Fiscal issuer is not authorized for the company.');
                }
            }
        };

        // Deliberately local and synthetic. The real coordinator's atomic
        // allocation and fencing are tested against a shared MySQL primary.
        $coordinator = $withSyntheticProof ? new class implements DpsSeriesCoordinator {
            private function assertScope(string $reference): void
            {
                if ($reference !== 'synthetic-feature-test-shared-db-only') {
                    throw new \LogicException('Synthetic test proof invalid.');
                }
            }

            public function allocate(
                DpsFiscalIssuer $issuer, int $environment, string $series,
                string $reference, string $operationKey,
            ): array {
                $this->assertScope($reference);

                $last = DB::table('nfse_dps_series')
                    ->where('issuer_municipality', $issuer->municipality)
                    ->where('issuer_registration_type', $issuer->registrationType)
                    ->where('issuer_registration', $issuer->registration)
                    ->where('environment', $environment)
                    ->where('series', $series)
                    ->value('last_number');

                return ['number' => ((int) $last) + 1, 'fresh' => true];
            }

            public function assertExisting(
                DpsFiscalIssuer $issuer, int $environment, string $series,
                string $reference, string $operationKey, int $number,
            ): void {
                $this->assertScope($reference);
            }
        } : null;

        return new DpsReservationService($authorizer, $coordinator);
    }

    private function invoice(): Document
    {
        return Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
    }

    private function issuer(): DpsFiscalIssuer
    {
        return new DpsFiscalIssuer('3303302', 2, '11222333000181');
    }

    private function activateSyntheticSeries(DpsFiscalIssuer $issuer, int $environment = 2, string $series = '00001'): void
    {
        DB::table('nfse_dps_series')->insert([
            'environment' => $environment,
            'issuer_municipality' => $issuer->municipality,
            'issuer_registration_type' => $issuer->registrationType,
            'issuer_registration' => $issuer->registration,
            'series' => $series,
            'status' => 'active',
            'coordination_mode' => 'shared_database',
            'coordination_reference' => 'synthetic-feature-test-shared-db-only',
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
