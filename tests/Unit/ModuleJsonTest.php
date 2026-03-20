<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit;

use Modules\Nfse\Tests\TestCase;

/**
 * Smoke test: verify module.json is valid JSON with required fields.
 */
class ModuleJsonTest extends TestCase
{
    private array $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/module.json';
        $this->manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAliasIsNfse(): void
    {
        self::assertSame('nfse', $this->manifest['alias']);
    }

    public function testProvidersContainMain(): void
    {
        self::assertContains(
            'Modules\\Nfse\\Providers\\Main',
            $this->manifest['providers'],
        );
    }

    public function testProvidersContainEvent(): void
    {
        self::assertContains(
            'Modules\\Nfse\\Providers\\Event',
            $this->manifest['providers'],
        );
    }

    public function testRedirectAfterInstall(): void
    {
        self::assertSame(
            'nfse.settings.edit',
            $this->manifest['routes']['redirect_after_install'],
        );
    }
}
