<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\CbsAssistedV2IncrementalReader;
use PHPUnit\Framework\TestCase;

final class CbsAssistedV2IncrementalReaderTest extends TestCase
{
    private const TICKET = 'ticket-123.test';

    public function testDebitsUseOfficialReportedBalanceWithoutSubtractingCreditsOrPayments(): void
    {
        $body = '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":['
            . '{"pa":"08/2026","debitos":[{"origem":0,"documento":91,"chave":"NFS_SYNTH_001",'
            . '"atualizacao":"2026-10-14T10:21:00Z","cbs":{"apurado":80.12,"saldoDevedor":40.06}}]}]}';

        $result = $this->parse($body, 'debitos');
        self::assertSame(hash('sha256', $body), $result['source_sha256']);
        self::assertSame('not_authenticated_by_parser', $result['source_provenance']);
        self::assertTrue($result['incremental']);
        self::assertFalse($result['complete_period']);
        self::assertSame('2026-08', $result['events'][0]['period']);
        self::assertSame('80.12', $result['events'][0]['official_reported_amount']);
        self::assertSame('40.06', $result['events'][0]['official_reported_balance']);
        self::assertSame('saldoDevedor', $result['events'][0]['balance_field']);
        self::assertSame('keyed', $result['events'][0]['identity_status']);
        self::assertSame('simulation_2026', $result['events'][0]['effect']);
    }

    public function testCreditBalanceIsReadFromAuthorizedPlatformFieldNotDocumentEstimate(): void
    {
        $body = '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":['
            . '{"pa":"01/2027","creditos":[{"origem":0,"documento":91,"chave":"NFS_SYNTH_002",'
            . '"atualizacao":"2027-01-15T12:00:00Z","cbs":{"apurado":25.60,'
            . '"apropriacao":{"utilizacao":{"naoUtilizado":{"saldoCredor":10.25}}}}}]}]}';

        $result = $this->parse($body, 'creditos');
        self::assertSame('25.60', $result['events'][0]['official_reported_amount']);
        self::assertSame('10.25', $result['events'][0]['official_reported_balance']);
        self::assertSame('saldoCredor', $result['events'][0]['balance_field']);
        self::assertSame('not_determined', $result['events'][0]['effect']);
    }

    public function testExactLargeDecimalAndUpdatedObservationRetainSameIdentityWithoutSumming(): void
    {
        $first = $this->debit('90071992547409.12', '90071992547409.10');
        $second = str_replace(['90071992547409.12', '90071992547409.10', '2026-08-13T20:00:00Z'],
            ['90071992547409.11', '90071992547409.09', '2026-08-14T20:00:00Z'], $first);

        $one = $this->parse($first, 'debitos')['events'][0];
        $two = $this->parse($second, 'debitos')['events'][0];

        self::assertSame('90071992547409.12', $one['official_reported_amount']);
        self::assertSame('90071992547409.09', $two['official_reported_balance']);
        self::assertSame($one['identity_key'], $two['identity_key']);
        self::assertNotSame($one['official_reported_balance'], $two['official_reported_balance']);
    }

    public function testUnidentifiedDocumentIsNotGroupedWithOtherEqualAmounts(): void
    {
        $body = str_replace('"chave":"NFS_SYNTH_001",', '', $this->debit('5.00', '5.00'));
        $result = $this->parse($body, 'debitos');

        self::assertNull($result['events'][0]['identity_key']);
        self::assertSame('unverifiable', $result['events'][0]['identity_status']);
    }

    public function testNoUpdatesIsNotProofOfZeroTaxOrCompletePeriod(): void
    {
        $result = $this->parse(
            '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":[]}',
            'creditos',
        );

        self::assertSame([], $result['events']);
        self::assertFalse($result['complete_period']);
    }

    public function testPaymentsReadEachDarfCompositionButNeverInferSettlementFromInvoice(): void
    {
        foreach (['pagamentos', 'recolhimentos'] as $kind) {
            $body = '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":['
                . '{"dataArrecadacao":"2026-10-20T12:00:00Z","pagamentos":[{"numeroDARF":"20261020000000001",'
                . '"tipo":3,"composicao":[{"sequencial":1,"pa":"10/2026","principal":10.25,'
                . '"multa":0.50,"juros":0.25,"total":11.00}]}]}]}';
            $result = $this->parse($body, $kind);
            self::assertSame('10.25', $result['events'][0]['principal']);
            self::assertSame('11.00', $result['events'][0]['reported_total']);
            self::assertSame(3, $result['events'][0]['payment_type_observed']);
            self::assertSame('simulation_2026', $result['events'][0]['effect']);
            self::assertSame('keyed', $result['events'][0]['identity_status']);
        }
    }

    public function testMalformedAndMismatchedPayloadsFailClosed(): void
    {
        foreach ([
            '{"tiqueteSolicitacao":"wrong","ni":"12345678","apuracao":[]}',
            '{"tiqueteSolicitacao":"ticket-123.test","ni":"87654321","apuracao":[]}',
            '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":{}}',
            '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":[',
            str_replace('80.12', '80.125', $this->debit('80.12', '40.06')),
            str_replace('80.12', '-1.00', $this->debit('80.12', '40.06')),
            str_replace('80.12', '8e2', $this->debit('80.12', '40.06')),
            str_replace('"saldoDevedor":40.06', '', $this->debit('80.12', '40.06')),
        ] as $index => $body) {
            try {
                $this->parse($body, 'debitos');
                self::fail('Untrusted CBS payload case ' . $index . ' must not become fiscal evidence.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    private function debit(string $amount, string $balance): string
    {
        return '{"tiqueteSolicitacao":"ticket-123.test","ni":"12345678","apuracao":['
            . '{"pa":"08/2026","debitos":[{"origem":0,"documento":91,"chave":"NFS_SYNTH_001",'
            . '"atualizacao":"2026-08-13T20:00:00Z","cbs":{"apurado":' . $amount
            . ',"saldoDevedor":' . $balance . '}}]}]}';
    }

    /** @return array<string,mixed> */
    private function parse(string $json, string $kind): array
    {
        return (new CbsAssistedV2IncrementalReader())->read(
            $json,
            $kind,
            '12345678',
            self::TICKET,
        );
    }
}
