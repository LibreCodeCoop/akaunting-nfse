<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Fiscal;

use Modules\Nfse\Application\IbsCbsEmissionReadiness;
use PHPUnit\Framework\TestCase;

final class IndependentTemporalOracleTest extends TestCase
{
    public function testTwentyAuditCasesMatchActualReadinessAcrossLegalCutovers(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fiscal-normative/nfse_427_matriz_temporal_sintetica_2026-10-12.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(20, $data['cases']);
        self::assertCount(20, array_unique(array_column($data['cases'], 'id')));

        $readiness = new IbsCbsEmissionReadiness();
        $mapping = [
            'ordinary_iss_service' => 'ordinary_lc116',
            'iss_deferred_subitem' => 'ordinary_lc116',
            'digital_platform_supply_subject_to_art22' => 'digital_platform',
            'real_estate_rental' => 'lease',
            'condominium_legal_charges' => 'condominium_revenue',
        ];

        foreach ($data['cases'] as $case) {
            self::assertArrayHasKey($case['primary_source'], $data['sources'], $case['id']);
            self::assertSame('independent_environment_specific_verification_required', $case['protocol_contract']);
            self::assertSame(self::independentCutover($case), $case['rtc_document_obligation_started'], $case['id'] . '/legal_calendar');

            $regime = match ($case['regime']) {
                'non_simples' => 1,
                'simples' => 3,
                default => 9,
            };
            $category = $mapping[$case['operation']] ?? 'unknown';
            $service = str_replace('.', '', (string) $case['lc116_subitem']);
            $date = $case['taxable_event_date'] ?? '';
            $result = $readiness->evaluate(
                $date,
                $regime,
                $service,
                ['ibs_cbs_enabled' => false],
                $category,
            );

            if ($case['rtc_document_obligation_started'] === 'unverifiable') {
                self::assertSame('unverifiable', $result['reason'], $case['id']);
                self::assertFalse($result['isReady'], $case['id']);

                continue;
            }

            self::assertSame($case['rtc_document_obligation_started'], $result['required'], $case['id']);

            if ($category !== 'ordinary_lc116') {
                self::assertFalse($result['isReady'], $case['id']);
                self::assertSame('unsupported_rtc_supply_category', $result['reason'], $case['id']);
            } elseif ($result['required']) {
                self::assertFalse($result['isReady'], $case['id']);
                self::assertContains('ibs_cbs_enabled', $result['missing'], $case['id']);
            } else {
                self::assertTrue($result['isReady'], $case['id']);
            }
        }
    }

    public function testTechnicalFakeAcceptanceDoesNotEstablishLegalCompleteness(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-10-01',
            1,
            '0101',
            ['ibs_cbs_enabled' => false],
        );
        $fakeSefin = static fn (string $payload): array => ['accepted' => true, 'synthetic' => true];
        $technical = $fakeSefin('<DPS><infDPS><serv><cTribNac>010101</cTribNac></serv></infDPS></DPS>');

        self::assertTrue($technical['accepted']); // Deliberately permissive fake.
        self::assertTrue($result['required']); // Independent legal obligation.
        self::assertFalse($result['isReady']); // Production readiness rejects omission.
        self::assertContains('ibs_cbs_enabled', $result['missing']);
        // This does not prove that all emission paths call the same readiness.
    }

    public function testPlannedXsdActivationIsNotAConfirmedDeployment(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fiscal-normative/nfse_427_matriz_temporal_sintetica_2026-10-12.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $data['transport_planned_not_activated']);
        foreach ($data['transport_planned_not_activated'] as $plan) {
            self::assertFalse($plan['deployment_confirmed']);
            self::assertNotEmpty($plan['sources']);
            foreach ($plan['sources'] as $source) {
                self::assertArrayHasKey($source, $data['sources']);
            }
        }
    }

    /**
     * Independent conditional calendar reconstructed from the source-backed audit.
     * This intentionally does not call IbsCbsObligationPolicy or other product rules.
     *
     * @param array<string,mixed> $case
     */
    private static function independentCutover(array $case): bool|string
    {
        $regime = $case['regime'];
        $category = $case['operation'];
        $dateString = $case['taxable_event_date'];
        $subitem = $case['lc116_subitem'];

        if (
            !in_array($regime, ['simples', 'non_simples'], true)
            || $category === 'unknown'
            || !is_string($dateString)
        ) {
            return 'unverifiable';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $dateString) {
            return 'unverifiable';
        }

        if ($regime === 'simples') {
            return $date >= new \DateTimeImmutable('2027-01-01');
        }

        if (in_array($category, [
            'digital_platform_supply_subject_to_art22',
            'real_estate_rental',
            'condominium_legal_charges',
        ], true)) {
            return $date >= new \DateTimeImmutable('2026-12-01');
        }

        $deferred = ['1.03', '1.05', '1.09', '16.01'];

        if ($category === 'iss_deferred_subitem' && in_array($subitem, $deferred, true)) {
            return $date >= new \DateTimeImmutable('2026-12-01');
        }

        if ($category === 'ordinary_iss_service' && !in_array($subitem, $deferred, true)) {
            return $date >= new \DateTimeImmutable('2026-10-01');
        }

        return 'unverifiable';
    }
}
