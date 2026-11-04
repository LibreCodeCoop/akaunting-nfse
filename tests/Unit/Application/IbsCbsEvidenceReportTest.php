<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IbsCbsEvidenceReport;
use PHPUnit\Framework\TestCase;

final class IbsCbsEvidenceReportTest extends TestCase
{
    public function testReportedZeroIsNotConfusedWithAnAbsentTaxField(): void
    {
        $report = (new IbsCbsEvidenceReport())->build([
            self::receipt(10, 'emitted', '0.00', '9.50'),
            self::receipt(11, 'emitted', null, '0.00'),
            self::receipt(12, 'emitted', '1.25', null),
        ]);

        self::assertSame(['reported_zero', 'not_reported', 'reported_amount'], array_column($report['rows'], 'ibs_status'));
        self::assertSame(['reported_amount', 'reported_zero', 'not_reported'], array_column($report['rows'], 'cbs_status'));
        self::assertSame(['0.00', '', '1.25'], array_column($report['rows'], 'ibs_amount'));
        self::assertSame('1.25', $report['summary']['ibs']['reported_total']);
        self::assertSame('9.50', $report['summary']['cbs']['reported_total']);
        self::assertSame(2, $report['summary']['ibs']['reported_count']);
        self::assertSame(2, $report['summary']['cbs']['reported_count']);
        self::assertSame(1, $report['summary']['ibs']['not_reported_count']);
        self::assertSame(1, $report['summary']['cbs']['not_reported_count']);
        self::assertSame(3, $report['summary']['active_documents']);
    }

    public function testCancelledAndSubstitutedReceiptsCannotContributeToTotals(): void
    {
        $report = (new IbsCbsEvidenceReport())->build([
            self::receipt(10, 'substituted', '99.00', '99.00'),
            self::receipt(11, 'cancelled', '99.00', '99.00'),
            self::receipt(12, 'emitted', '2.00', '3.00'),
        ]);

        self::assertSame('2.00', $report['summary']['ibs']['reported_total']);
        self::assertSame('3.00', $report['summary']['cbs']['reported_total']);
        self::assertSame(2, $report['summary']['excluded_documents']);
        self::assertSame('excluded_non_active', $report['rows'][0]['ibs_status']);
        self::assertSame('', $report['rows'][0]['ibs_amount']);
        self::assertSame('excluded_non_active', $report['rows'][1]['cbs_status']);
        self::assertSame(0, $report['summary']['ibs']['not_reported_count']);
    }

    public function testMissingOrUntrustworthySnapshotIsNotTaxEvidence(): void
    {
        $missing = self::receipt(10, 'emitted', '30.00', '40.00');
        $missing->authorized_fiscal_snapshot = null;

        $badSource = self::receipt(11, 'emitted', '30.00', '40.00');
        $badSource->authorized_fiscal_snapshot['source'] = 'invoice_tax_calculation';

        $badHash = self::receipt(12, 'emitted', '30.00', '40.00');
        $badHash->authorized_fiscal_snapshot['source_sha256'] = 'invalid';

        $report = (new IbsCbsEvidenceReport())->build([$missing, $badSource, $badHash]);

        self::assertSame(3, $report['summary']['missing_snapshots']);
        self::assertSame('0.00', $report['summary']['ibs']['reported_total']);
        self::assertSame(3, $report['summary']['ibs']['not_reported_count']);
        self::assertSame(['snapshot_unavailable', 'snapshot_unavailable', 'snapshot_unavailable'], array_column($report['rows'], 'ibs_status'));
    }

    public function testInvalidOrSubCentTaxAmountsAreNotRoundedOrSilentlyZeroed(): void
    {
        $report = (new IbsCbsEvidenceReport())->build([
            self::receipt(10, 'emitted', '1.005', 'unparseable'),
            self::receipt(11, 'emitted', '10.00', '0'),
        ]);

        self::assertSame('invalid_amount', $report['rows'][0]['ibs_status']);
        self::assertSame('invalid_amount', $report['rows'][0]['cbs_status']);
        self::assertSame('', $report['rows'][0]['ibs_amount']);
        self::assertSame('10.00', $report['summary']['ibs']['reported_total']);
        self::assertSame('0.00', $report['summary']['cbs']['reported_total']);
        self::assertSame(1, $report['summary']['ibs']['invalid_count']);
        self::assertSame(1, $report['summary']['cbs']['invalid_count']);
    }

    public function testRepeatedAuthorizedXmlHashIsNotDoubleCounted(): void
    {
        $original = self::receipt(10, 'emitted', '9.00', '7.00');
        $duplicate = self::receipt(11, 'emitted', '9.00', '7.00');
        $duplicate->authorized_fiscal_snapshot['source_sha256'] =
            $original->authorized_fiscal_snapshot['source_sha256'];

        $report = (new IbsCbsEvidenceReport())->build([$original, $duplicate]);

        self::assertSame('9.00', $report['summary']['ibs']['reported_total']);
        self::assertSame('7.00', $report['summary']['cbs']['reported_total']);
        self::assertSame(1, $report['summary']['duplicate_documents']);
        self::assertSame('duplicate_source', $report['rows'][1]['ibs_status']);
        self::assertSame('duplicate_source', $report['rows'][1]['cbs_status']);
        self::assertSame('', $report['rows'][1]['ibs_amount']);
        self::assertSame(1, $report['summary']['ibs']['reported_count']);
    }

    public function testNegativeTaxTotalsCannotOffsetPositiveReportedEvidence(): void
    {
        $report = (new IbsCbsEvidenceReport())->build([
            self::receipt(10, 'emitted', '-10.00', '-2.00'),
            self::receipt(11, 'emitted', '1.00', '3.00'),
        ]);

        self::assertSame('invalid_amount', $report['rows'][0]['ibs_status']);
        self::assertSame('invalid_amount', $report['rows'][0]['cbs_status']);
        self::assertSame('1.00', $report['summary']['ibs']['reported_total']);
        self::assertSame('3.00', $report['summary']['cbs']['reported_total']);
    }

    public function testMalformedMetadataCannotBecomeSpreadsheetFormulas(): void
    {
        $receipt = self::receipt(10, '=SUM(1,2)', '1.00', '2.00');
        $receipt->competence_date = '=HYPERLINK("example.invalid")';

        $report = (new IbsCbsEvidenceReport())->build([$receipt]);

        self::assertSame('unknown', $report['rows'][0]['receipt_status']);
        self::assertSame('', $report['rows'][0]['competence']);
        self::assertSame('excluded_non_active', $report['rows'][0]['ibs_status']);
        self::assertSame(0, $report['summary']['active_documents']);
    }

    private static function receipt(int $id, string $status, ?string $ibs, ?string $cbs): object
    {
        return (object) [
            'id' => $id,
            'invoice_id' => $id + 100,
            'status' => $status,
            'competence_date' => '2026-10-10',
            'authorized_fiscal_snapshot' => [
                'source' => 'authorized_xml',
                'source_sha256' => hash('sha256', 'synthetic-document-' . $id),
                'ibs_cbs' => ['ibs_total' => $ibs, 'cbs_total' => $cbs],
            ],
        ];
    }
}
