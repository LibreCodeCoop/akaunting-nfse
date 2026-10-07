<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ModuleVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleVersionTest extends TestCase
{
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
