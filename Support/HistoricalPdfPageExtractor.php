<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extract exactly one page of a consolidated historical PDF.
 *
 * The archived original may contain other customers' fiscal documents.
 * Never deliver the complete archive to an invoice/receipt-scoped request.
 */
final class HistoricalPdfPageExtractor
{
    public function extract(string $source, int $page): string
    {
        if ($page < 1 || $page > 500 || strlen($source) > 50 * 1024 * 1024
            || !str_starts_with($source, '%PDF-')) {
            throw new RuntimeException('Invalid historical PDF or page selection.');
        }

        $process = new Process([
            '/usr/bin/gs',
            '-q',
            '-dSAFER',
            '-dNOPAUSE',
            '-dBATCH',
            '-sDEVICE=pdfwrite',
            '-dFirstPage=' . $page,
            '-dLastPage=' . $page,
            '-sOutputFile=-',
            '-',
        ]);

        $process->setTimeout(30);
        $process->setInput($source);
        $process->run();
        $extracted = $process->getOutput();

        if (!$process->isSuccessful() || !str_starts_with($extracted, '%PDF-')
            || strlen($extracted) > 20 * 1024 * 1024) {
            throw new RuntimeException('Failed to isolate the requested historical PDF page.');
        }

        return $extracted;
    }
}
