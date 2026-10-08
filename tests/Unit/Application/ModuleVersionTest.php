<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ModuleVersion;
use Modules\Nfse\Application\PackageVersionProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleVersionTest extends TestCase
{
    public function testUsesPackageVersionWhenNoManifestIsExplicitlyProvided(): void
    {
        $provider = new class () implements PackageVersionProvider {
            public function get(string $packageName): ?string
            {
                TestCase::assertSame('librecodeoop/akaunting-nfse', $packageName);

                return '3.2.1';
            }
        };

        self::assertSame('3.2.1', (new ModuleVersion(packageVersions: $provider))->get());
    }

    public function testFallsBackToSourceManifestWhenPackageVersionIsUnavailable(): void
    {
        $provider = new class () implements PackageVersionProvider {
            public function get(string $packageName): ?string
            {
                return null;
            }
        };

        self::assertSame('0.0.0-dev', (new ModuleVersion(packageVersions: $provider))->get());
    }

    public function testExplicitManifestBypassesPackageVersionProvider(): void
    {
        $path = $this->manifest(['version' => '2.4.1']);
        $provider = new class () implements PackageVersionProvider {
            public function get(string $packageName): ?string
            {
                TestCase::fail('Package version provider must not be called for an explicit manifest.');
            }
        };

        try {
            self::assertSame('2.4.1', (new ModuleVersion($path, $provider))->get());
        } finally {
            @unlink($path);
        }
    }

    public function testReadsVersionFromModuleManifest(): void
    {
        $path = $this->manifest(['version' => '2.4.1']);

        try {
            self::assertSame('2.4.1', (new ModuleVersion($path))->get());
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsManifestWithoutVersion(): void
    {
        $path = $this->manifest(['alias' => 'nfse']);

        try {
            $this->expectException(RuntimeException::class);
            (new ModuleVersion($path))->get();
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string,mixed> $contents
     */
    private function manifest(array $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-module-');

        if ($path === false) {
            self::fail('Unable to create temporary manifest.');
        }

        file_put_contents($path, json_encode($contents, JSON_THROW_ON_ERROR));

        return $path;
    }
}
