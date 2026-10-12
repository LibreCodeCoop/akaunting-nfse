<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\Config\BundledDanfseFonts;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\ArtifactException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScopedDanfseFontsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function approvedFontFingerprints(): iterable
    {
        yield 'regular' => ['LiberationSans-Regular.ttf', '76d04c18ea243f426b7de1f3ad208e927008f961dc5945e5aad352d0dfde8ee8'];
        yield 'bold' => ['LiberationSans-Bold.ttf', '788abee4c806d660e8aee46689dd8540cd4bb98da03dcc9d171ce3efd99a9173'];
    }

    #[DataProvider('approvedFontFingerprints')]
    public function testScopedFontAssetsMatchApprovedRelease(string $name, string $sha256): void
    {
        $path = self::scopedPackageRoot() . '/src/Danfse/Assets/fonts/' . $name;
        self::assertFileExists($path);
        self::assertSame($sha256, hash_file('sha256', $path));
    }

    public function testScopedPackageIncludesOflAndNoticeAndResolvesLocalFonts(): void
    {
        $root = self::scopedPackageRoot();
        $notice = file_get_contents($root . '/src/Danfse/Assets/fonts/NOTICE.md');
        $license = file_get_contents($root . '/LICENSES/OFL-1.1.txt');

        self::assertIsString($notice);
        self::assertIsString($license);
        self::assertStringContainsString('Liberation Sans', $notice);
        self::assertStringContainsString('OFL-1.1', $notice);
        self::assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $license);

        $fonts = BundledDanfseFonts::get();
        self::assertSame(realpath($root . '/src/Danfse/Assets/fonts/LiberationSans-Regular.ttf'), $fonts->arialRegular);
        self::assertSame(realpath($root . '/src/Danfse/Assets/fonts/LiberationSans-Bold.ttf'), $fonts->arialBold);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function invalidBundledFontCases(): iterable
    {
        yield 'missing' => [false, 'missing or unreadable'];
        yield 'corrupt' => [true, 'failed integrity check'];
    }

    #[DataProvider('invalidBundledFontCases')]
    public function testScopedFontValidationRejectsMissingOrCorruptFile(bool $leaveFile, string $expectedError): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-font-proof-');
        self::assertIsString($path);

        if (!$leaveFile) {
            unlink($path);
        } else {
            file_put_contents($path, 'synthetic invalid font');
        }

        try {
            BundledDanfseFonts::assertValidFile($path, str_repeat('0', 64));
            self::fail('Expected an explicit font validation error.');
        } catch (ArtifactException $exception) {
            self::assertStringContainsString($expectedError, $exception->getMessage());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function scopedPackageRoot(): string
    {
        return dirname(__DIR__, 3) . '/3rdparty/scoped/librecodeoop/nfse-php';
    }
}
