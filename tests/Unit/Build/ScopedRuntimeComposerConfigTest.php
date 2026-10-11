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

    public function testScoperPatcherPrefixesDompdfDynamicClasses(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor-bin/php-scoper/vendor/autoload.php';

        $config = require dirname(__DIR__, 3) . '/3rdparty/scoper.inc.php';
        $patchers = $config['patchers'] ?? null;

        self::assertIsArray($patchers);
        self::assertCount(2, $patchers);

        $source = <<<'PHP'
            $decorator = "Dompdf\\FrameDecorator\\{$decorator}";
            $reflower = "Dompdf\\FrameReflower\\{$reflower}";
            $class = '\\Dompdf\\Positioner\\'.$type;
            $class = '\Dompdf\Positioner\\' . $type;
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
            '$decorator = "Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\FrameDecorator\\\\{$decorator}";',
            $patchedContent,
        );
        self::assertStringContainsString(
            '$reflower = "Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\FrameReflower\\\\{$reflower}";',
            $patchedContent,
        );
        self::assertStringContainsString(
            '$class = \'\\\\Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\Positioner\\\\\'.$type;',
            $patchedContent,
        );
        self::assertStringContainsString(
            '$class = \'\\\\Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\Positioner\\\\\' . $type;',
            $patchedContent,
        );
    }

    public function testBuiltScopedDompdfFactoryIsValidAndFullyPrefixed(): void
    {
        $factoryPath = dirname(__DIR__, 3) . '/3rdparty/scoped/dompdf/dompdf/src/Frame/Factory.php';
        $content = file_get_contents($factoryPath);

        self::assertIsString($content);
        self::assertStringContainsString(
            '$decorator = "Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\FrameDecorator\\\\{$decorator}";',
            $content,
        );
        self::assertStringContainsString(
            '$reflower = "Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\FrameReflower\\\\{$reflower}";',
            $content,
        );
        self::assertStringContainsString(
            '$class = \'\\\\Modules\\\\Nfse\\\\Vendor\\\\Dompdf\\\\Positioner\\\\\' . $type;',
            $content,
        );
        self::assertStringNotContainsString('"Dompdf\\\\FrameDecorator\\\\{$decorator}"', $content);
        self::assertStringNotContainsString('"Dompdf\\\\FrameReflower\\\\{$reflower}"', $content);

        exec(
            escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($factoryPath) . ' 2>&1',
            $lintOutput,
            $lintExitCode,
        );

        self::assertSame(0, $lintExitCode, implode("\n", $lintOutput));
    }

    public function testScopedDanfseIncludesTheNationalLogoAndLayout(): void
    {
        require_once dirname(__DIR__, 3) . '/3rdparty/scoped/autoload.php';

        $configClass = 'Modules\\Nfse\\Vendor\\LibreCodeCoop\\NfsePHP\\Danfse\\Config\\DanfseConfig';
        $config = new $configClass();
        $configPath = (new \ReflectionClass($configClass))->getFileName();
        self::assertIsString($configPath);
        $asset = dirname($configPath) . '/../Assets/nfse-horizontal.png';
        self::assertFileExists($asset);
        $info = getimagesize($asset);
        self::assertIsArray($info);
        self::assertSame('image/png', $info['mime']);
        self::assertIsString($config->logoDataUri);
        self::assertStringStartsWith('data:image/png;base64,', $config->logoDataUri);

        $xml = file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);

        $generatorClass = 'Modules\\Nfse\\Vendor\\LibreCodeCoop\\NfsePHP\\Danfse\\DanfseGenerator';
        $html = (new $generatorClass())->generateHtml($xml);
        self::assertStringContainsString('alt="Logo NFS-e"', $html);
        self::assertStringContainsString('PRESTADOR / FORNECEDOR', $html);
        self::assertStringContainsString('TRIBUTAÇÃO MUNICIPAL (ISSQN)', $html);
        self::assertStringContainsString('Nº NFS-e / CHAVE NFS-e', $html);
        // The scoped package must contain the revised NT 008 DANFSe grid,
        // not the previously pinned renderer.
        self::assertStringContainsString('class="emitter-cell"', $html);
        self::assertStringContainsString('class="total-emphasis"', $html);
        self::assertStringContainsString('rowspan="4"', $html);
        self::assertMatchesRegularExpression(
            '/class="section-header">\\s*<span class="section-title">TRIBUTAÇÃO IBS \\/ CBS/',
            $html,
        );
    }

    public function testScopedDanfseGeneratorRendersPdf(): void
    {
        require_once dirname(__DIR__, 3) . '/3rdparty/scoped/autoload.php';

        $fixturePath = dirname(__DIR__, 3) . '/tests/fixtures/nfse_exemplo.xml';
        $xml = file_get_contents($fixturePath);

        self::assertIsString($xml);

        $generatorClass = 'Modules\\Nfse\\Vendor\\LibreCodeCoop\\NfsePHP\\Danfse\\DanfseGenerator';
        $generator = new $generatorClass();
        $pdf = $generator->generateFromXml($xml);

        self::assertNotSame('', $pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
