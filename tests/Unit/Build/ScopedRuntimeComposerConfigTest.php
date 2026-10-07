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
        self::assertStringContainsString('"runtime-tools:install": "composer --working-dir=vendor-bin/php-scoper install', $content);
        self::assertStringContainsString('"dev-tools:install": [', $content);
        self::assertStringContainsString('composer --working-dir=vendor-bin/php-scoper install', $content);
        self::assertStringContainsString('composer --working-dir=vendor-bin/phpunit install', $content);
        self::assertStringContainsString('composer --working-dir=vendor-bin/behat install', $content);
        self::assertStringContainsString('composer --working-dir=vendor-bin/php-cs-fixer install', $content);
        self::assertStringContainsString('composer --working-dir=vendor-bin/psalm install', $content);
        self::assertStringContainsString('"thirdparty:scope": [', $content);
        self::assertStringContainsString('"thirdparty:build:prod": [', $content);
        self::assertStringContainsString('"thirdparty:build:auto": [', $content);
        self::assertStringContainsString('COMPOSER_DEV_MODE', $content);
        self::assertStringContainsString('Composer\\\\Config::disableProcessTimeout', $content);
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
        self::assertStringContainsString('"librecodeoop/nfse-php": "dev-main#4ca17300ee3513fb7918c5ba1ad398b1e824bea6"', $content);
        self::assertStringContainsString('"url": "https://github.com/LibreCodeCoop/nfse-php"', $content);
        self::assertMatchesRegularExpression(
            '/"librecodeoop\/nfse-php": "dev-main#[0-9a-f]{40}"/',
            $content,
        );
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

        self::assertFileDoesNotExist($moduleRoot . '/vendor-bin/nfse-runtime/composer.json');

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
        self::assertStringNotContainsString("'composer'", $content);
        self::assertStringContainsString("'patchers' => [", $content);
        self::assertStringContainsString("composer/autoload_real.php", $content);
    }

    public function testScoperPatcherSupportsPrefixedComposerClassLoader(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor-bin/php-scoper/vendor/autoload.php';

        $config = require dirname(__DIR__, 3) . '/3rdparty/scoper.inc.php';
        $patchers = $config['patchers'] ?? null;

        self::assertIsArray($patchers);
        self::assertCount(2, $patchers);

        $patchedContent = "if ('Composer\\Autoload\\ClassLoader' === \$class) {";

        foreach ($patchers as $patcher) {
            $patchedContent = $patcher(
                'composer/autoload_real.php',
                'Modules\\Nfse\\Vendor',
                $patchedContent,
            );
        }

        self::assertSame(
            "if ('Composer\\Autoload\\ClassLoader' === \$class || 'Modules\\Nfse\\Vendor\\Composer\\Autoload\\ClassLoader' === \$class) {",
            $patchedContent,
        );
    }

    public function testScoperPatcherUsesNamespaceRelativeDompdfDynamicClasses(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor-bin/php-scoper/vendor/autoload.php';

        $config = require dirname(__DIR__, 3) . '/3rdparty/scoper.inc.php';
        $patchers = $config['patchers'] ?? null;

        self::assertIsArray($patchers);
        self::assertCount(2, $patchers);

        $source = <<<'PHP'
            $decorator  = "Dompdf\\FrameDecorator\\$decorator";
            $reflower   = "Dompdf\\FrameReflower\\$reflower";
            $class = '\\Dompdf\\Positioner\\'.$type;
            PHP;

        $patchedContent = $source;

        foreach ($patchers as $patcher) {
            $patchedContent = $patcher(
                '/vendor/dompdf/dompdf/src/Frame/Factory.php',
                'Modules\\Nfse\\Vendor',
                $patchedContent,
            );
        }

        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\FrameDecorator\\\\' . \$decorator",
            $patchedContent,
        );
        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\FrameReflower\\\\' . \$reflower",
            $patchedContent,
        );
        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\Positioner\\\\' . \$type",
            $patchedContent,
        );
    }

    public function testBuiltScopedDompdfFactoryContainsNoUnscopedDynamicClassNames(): void
    {
        $factoryPath = dirname(__DIR__, 3) . '/3rdparty/scoped/dompdf/dompdf/src/Frame/Factory.php';
        $content = file_get_contents($factoryPath);

        self::assertIsString($content);
        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\FrameDecorator\\\\' . \$decorator",
            $content,
        );
        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\FrameReflower\\\\' . \$reflower",
            $content,
        );
        self::assertStringContainsString(
            "substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, '\\\\Frame')) . '\\\\Positioner\\\\' . \$type",
            $content,
        );
        self::assertStringNotContainsString('"Dompdf\\\\FrameDecorator\\\\$decorator"', $content);
        self::assertStringNotContainsString('"Dompdf\\\\FrameReflower\\\\$reflower"', $content);
    }}
