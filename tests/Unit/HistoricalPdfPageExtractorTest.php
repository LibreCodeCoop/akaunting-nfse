<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit;

use Modules\Nfse\Support\HistoricalPdfPageExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class HistoricalPdfPageExtractorTest extends TestCase
{
    public function testIsolatesSecondCustomerWithoutLeakingFirstPage(): void
    {
        if (!is_executable('/usr/bin/gs')) {
            self::markTestSkipped('Ghostscript is not installed; archive PDF extraction must fail closed.');
        }

        $postscript = <<<'PS'
%!PS-Adobe-3.0
%%Pages: 2
/Courier findfont 18 scalefont setfont
72 720 moveto (FIRST_CUSTOMER_CONFIDENTIAL) show
showpage
/Courier findfont 18 scalefont setfont
72 720 moveto (SECOND_CUSTOMER_SELECTED) show
showpage
%%EOF
PS;
        $generator = new Process([
            '/usr/bin/gs', '-q', '-dSAFER', '-dNOPAUSE', '-dBATCH',
            '-sDEVICE=pdfwrite', '-sOutputFile=-', '-',
        ]);
        $generator->setInput($postscript);
        $generator->mustRun();

        $extractor = new HistoricalPdfPageExtractor();
        $result = $extractor->extract($generator->getOutput(), 2);

        self::assertStringStartsWith('%PDF-', $result);

        $textReader = new Process([
            '/usr/bin/gs', '-q', '-dSAFER', '-dNOPAUSE', '-dBATCH',
            '-sDEVICE=txtwrite', '-sOutputFile=-', '-',
        ]);
        $textReader->setInput($result);
        $textReader->mustRun();
        $text = $textReader->getOutput();

        self::assertStringContainsString('SECOND_CUSTOMER_SELECTED', $text);
        self::assertStringNotContainsString('FIRST_CUSTOMER_CONFIDENTIAL', $text);
    }

    public function testRejectsInvalidPageAndNonPdf(): void
    {
        $extractor = new HistoricalPdfPageExtractor();

        foreach ([0, -1, 501] as $page) {
            try {
                $extractor->extract('%PDF-1.7', $page);
                self::fail('Out-of-range page must not be served.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(RuntimeException::class);
        $extractor->extract('not a PDF', 1);
    }
}
