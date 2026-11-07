<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit;

use DomainException;
use Modules\Nfse\Application\HistoricalReceiptManifest;
use PHPUnit\Framework\TestCase;

final class HistoricalReceiptManifestTest extends TestCase
{
    private const ISSUER = '00000000000191';
    private const RECIPIENT = '00000000000272';

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'company_id' => 1,
            'invoice_id' => null,
            'issuer_tax' => self::ISSUER,
            'recipient_tax' => self::RECIPIENT,
            'nfse_number' => '007',
            'data_emissao' => '2018-06-14 14:00:00',
            'status' => 'emitted',
            'danfse_webdav_path' => '/LibreCode/Fiscal/Historico/2018/multiple-notes.pdf',
            'archive_page_number' => 2,
            'archive_pdf_sha256' => str_repeat('a', 64),
            'codigo_verificacao' => 'TEST-ONLY',
            'archive_evidence' => [
                'gross_cents' => 15600,
                'net_cents' => 14500,
            ],
            'replaces_fiscal_number' => '6',
            'recipient_name_ocr' => 'Cliente Ficticio Ltda',
        ];
    }

    public function testNormalizationDoesNotCreateAccountingDocumentsOrPayments(): void
    {
        $row = HistoricalReceiptManifest::normalize($this->sample(), 1, self::ISSUER);
        self::assertNull($row['invoice_id']);
        self::assertNull($row['recipient_contact_id']);
        self::assertSame(1, $row['company_id']);
        self::assertSame(2, $row['archive_page_number']);
        self::assertSame(str_repeat('a', 64), $row['archive_pdf_sha256']);
        self::assertSame('Cliente Ficticio Ltda', $row['archive_evidence']['recipient_name']);
        self::assertSame('ocr_unverified', $row['archive_evidence']['recipient_name_source']);
        self::assertSame('6', $row['archive_evidence']['replaces_fiscal_number']);
        self::assertSame(15600, $row['archive_evidence']['gross_cents']);
        self::assertArrayNotHasKey('due_at', $row);
        self::assertArrayNotHasKey('amount', $row);
    }

    public function testFiscalArchiveIdentityIsStableAcrossLeadingZeroes(): void
    {
        $a = $this->sample();
        $b = $a;
        $b['nfse_number'] = '7';

        self::assertSame(
            HistoricalReceiptManifest::normalize($a, 1, self::ISSUER)['archive_identity'],
            HistoricalReceiptManifest::normalize($b, 1, self::ISSUER)['archive_identity'],
        );
        self::assertNotSame(
            HistoricalReceiptManifest::normalize($a, 1, self::ISSUER)['archive_identity'],
            HistoricalReceiptManifest::normalize($b, 2, self::ISSUER)['archive_identity'],
        );
    }

    public function testCancelledNoteMayLackGrossValueWithoutBecomingIncome(): void
    {
        $sample = $this->sample();
        $sample['status'] = 'cancelled';
        $sample['archive_evidence']['gross_cents'] = null;
        $normalized = HistoricalReceiptManifest::normalize($sample, 1, self::ISSUER);

        self::assertSame('cancelled', $normalized['status']);
        self::assertNull($normalized['invoice_id']);
        self::assertNull($normalized['archive_evidence']['gross_cents']);
    }

    /** @dataProvider unsafeInputs */
    public function testBadHistoricalEvidenceIsRejected(string $path, mixed $value): void
    {
        $sample = $this->sample();
        if ($path === 'gross_cents') {
            $sample['archive_evidence']['gross_cents'] = $value;
        } else {
            $sample[$path] = $value;
        }

        $this->expectException(DomainException::class);
        HistoricalReceiptManifest::normalize($sample, 1, self::ISSUER);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unsafeInputs(): iterable
    {
        yield 'invalid issuer' => ['issuer_tax', '12345678000199'];
        yield 'invalid recipient' => ['recipient_tax', '00000000000200'];
        yield 'unreviewed commercial invoice' => ['invoice_id', 456];
        yield 'invalid date' => ['data_emissao', '2018-02-30 14:00:00'];
        yield 'fake status' => ['status', 'paid'];
        yield 'no archived file' => ['danfse_webdav_path', 'LibreCode/Fiscal/../sneaky.pdf'];
        yield 'not a PDF' => ['danfse_webdav_path', 'LibreCode/Fiscal/data.zip'];
        yield 'foreign path' => ['danfse_webdav_path', '/External/notes.pdf'];
        yield 'missing SHA-256' => ['archive_pdf_sha256', 'abc'];
        yield 'page outside PDF' => ['archive_page_number', 0];
        yield 'zero gross emitted' => ['gross_cents', 0];
        yield 'string gross' => ['gross_cents', '15600'];
    }
}
