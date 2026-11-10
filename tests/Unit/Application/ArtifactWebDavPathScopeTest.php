<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ArtifactWebDavPathScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactWebDavPathScopeTest extends TestCase
{
    #[DataProvider('scopes')]
    public function testDefaultIsolationAndExplicitConfiguredRoot(string $scope, int $companyId, string $expected): void
    {
        $actual = (new ArtifactWebDavPathScope())->apply(
            '/Client/Finance/2026/11/NFSe/',
            $companyId,
            $scope,
        );

        self::assertSame($expected, $actual);
    }

    /** @return array<string,array{string,int,string}> */
    public static function scopes(): array
    {
        return [
            'tenant one' => ['company', 1, 'companies/1/Client/Finance/2026/11/NFSe'],
            'tenant two' => ['company', 2, 'companies/2/Client/Finance/2026/11/NFSe'],
            'exact dedicated root' => ['configured', 1, 'Client/Finance/2026/11/NFSe'],
            'invalid stored mode fails closed' => ['unknown', 2, 'companies/2/Client/Finance/2026/11/NFSe'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsOrTenantAreRejected(string $path, int $companyId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ArtifactWebDavPathScope())->apply($path, $companyId, 'configured');
    }

    /** @return array<string,array{string,int}> */
    public static function invalidPaths(): array
    {
        return [
            'missing company' => ['Client/2026', 0],
            'empty path' => ['', 1],
            'path traversal' => ['Client/../Other', 1],
        ];
    }
}
