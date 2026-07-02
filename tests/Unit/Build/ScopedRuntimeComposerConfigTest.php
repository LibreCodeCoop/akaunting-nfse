<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;

final class ScopedRuntimeComposerConfigTest extends TestCase
{
    public function testRootComposerUsesComposerBinPluginForScopedRuntimeBuild(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        $content = file_get_contents($composerPath);

        self::assertIsString($content);
        self::assertStringContainsString('"bamarni/composer-bin-plugin": "^1.8"', $content);
        self::assertStringContainsString('"dev-tools:install": [', $content);
        self::assertStringContainsString('@composer bin php-scoper install', $content);
        self::assertStringContainsString('@composer bin phpunit install', $content);
        self::assertStringContainsString('@composer bin behat install', $content);
        self::assertStringContainsString('@composer bin php-cs-fixer install', $content);
        self::assertStringContainsString('@composer bin psalm install', $content);
        self::assertStringContainsString('vendor-bin/php-scoper/vendor/bin/php-scoper', $content);
        self::assertStringContainsString('vendor-bin/phpunit/vendor/bin/phpunit', $content);
        self::assertStringContainsString('vendor-bin/behat/vendor/bin/behat', $content);
        self::assertStringContainsString('vendor-bin/php-cs-fixer/vendor/bin/php-cs-fixer', $content);
        self::assertStringContainsString('vendor-bin/psalm/vendor/bin/psalm', $content);
    }

    public function testScopedRuntimeManifestLivesUnder3rdparty(): void
    {
        $manifestPath = dirname(__DIR__, 3) . '/3rdparty/composer.json';
        $content = file_get_contents($manifestPath);

        self::assertIsString($content);
        self::assertStringContainsString('"librecodeoop/nfse-php": "dev-main"', $content);
    }

    public function testPhpScoperToolingLivesUnderVendorBin(): void
    {
        $manifestPath = dirname(__DIR__, 3) . '/vendor-bin/php-scoper/composer.json';
        $content = file_get_contents($manifestPath);

        self::assertIsString($content);
        self::assertStringContainsString('"humbug/php-scoper": "^0.18"', $content);
    }

    public function testAdditionalDevToolsEachLiveInTheirOwnVendorBin(): void
    {
        $moduleRoot = dirname(__DIR__, 3);

        foreach ([
            'behat' => '"behat/behat": "^3.16"',
            'phpunit' => '"phpunit/phpunit": "^11.0"',
            'php-cs-fixer' => '"friendsofphp/php-cs-fixer": "^3.0"',
            'psalm' => '"vimeo/psalm": "^6.0"',
        ] as $binName => $expectedPackage) {
            $content = file_get_contents($moduleRoot . '/vendor-bin/' . $binName . '/composer.json');

            self::assertIsString($content);
            self::assertStringContainsString($expectedPackage, $content);
        }
    }

    public function testScoperConfigStillPublishesRuntimeAutoloadUnder3rdparty(): void
    {
        $configPath = dirname(__DIR__, 3) . '/3rdparty/scoper.inc.php';
        $content = file_get_contents($configPath);

        self::assertIsString($content);
        self::assertStringContainsString("'prefix' => 'Modules\\\\Nfse\\\\Vendor'", $content);
        self::assertStringContainsString("'/scoped'", $content);
        self::assertStringContainsString("__DIR__ . '/vendor'", $content);
    }
}
