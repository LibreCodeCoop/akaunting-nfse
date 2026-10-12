<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Tools\DanfsePackageEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

require_once dirname(__DIR__, 3) . '/tools/src/DanfsePackageEvidence.php';

final class DanfsePackageEvidenceTest extends TestCase
{
    public function testBuiltArchiveIncludesTheApprovedDanfseAssets(): void
    {
        $archivePath = $this->createArchive();
        try {
            self::assertSame([], DanfsePackageEvidence::inspect($archivePath));
        } finally {
            unlink($archivePath);
        }
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function brokenResources(): iterable
    {
        yield 'missing regular font' => ['3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/LiberationSans-Regular.ttf', null, 'Missing font'];
        yield 'corrupt bold font' => ['3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/LiberationSans-Bold.ttf', 'invalid font', 'Invalid font SHA-256'];
        yield 'missing OFL license' => ['3rdparty/scoped/librecodeoop/nfse-php/LICENSES/OFL-1.1.txt', null, 'Missing runtime resource'];
        yield 'invalid NOTICE' => ['3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/NOTICE.md', 'incorrect notice', 'Invalid runtime resource'];
        yield 'missing scoped loader' => ['3rdparty/scoped/autoload.php', null, 'Missing runtime resource'];
        yield 'missing Dompdf' => ['3rdparty/scoped/dompdf/dompdf/src/Dompdf.php', null, 'Missing runtime resource'];
    }

    #[DataProvider('brokenResources')]
    public function testRejectsMissingOrInvalidPackagedResource(string $path, ?string $replacement, string $message): void
    {
        $archivePath = $this->createArchive($path, $replacement);
        try {
            self::assertStringContainsString($message, implode('; ', DanfsePackageEvidence::inspect($archivePath)));
        } finally {
            unlink($archivePath);
        }
    }

    public function testRejectsInvalidZip(): void
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'danfse-package-');
        self::assertIsString($archivePath);
        file_put_contents($archivePath, 'not a ZIP file');
        try {
            self::assertSame(['Cannot open module archive.'], DanfsePackageEvidence::inspect($archivePath));
        } finally {
            unlink($archivePath);
        }
    }

    private function createArchive(?string $changedPath = null, ?string $replacement = null): string
    {
        $root = dirname(__DIR__, 3);
        $archivePath = tempnam(sys_get_temp_dir(), 'danfse-package-');
        self::assertIsString($archivePath);
        $archive = new ZipArchive();
        self::assertTrue($archive->open($archivePath, ZipArchive::OVERWRITE) === true);

        $entries = array_merge(
            array_keys(DanfsePackageEvidence::FONT_SHA256),
            array_keys(DanfsePackageEvidence::REQUIRED_CONTENT),
        );
        foreach ($entries as $path) {
            if ($path === $changedPath && $replacement === null) {
                continue;
            }

            $content = $path === $changedPath ? $replacement : file_get_contents($root . '/' . $path);
            self::assertIsString($content, 'Missing scoped source: ' . $path);
            self::assertTrue($archive->addFromString('Nfse/' . $path, $content));
        }
        self::assertTrue($archive->close());

        return $archivePath;
    }
}
