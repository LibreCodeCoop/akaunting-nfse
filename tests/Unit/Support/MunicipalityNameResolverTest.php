<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\MunicipalityNameResolver;
use PHPUnit\Framework\TestCase;

final class MunicipalityNameResolverTest extends TestCase
{
    public function testResolvesUnambiguousNameAndStateWithAccentNormalization(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ibge-');
        self::assertIsString($path);
        file_put_contents($path, "3550308\tSP\tSão Paulo\n3304557\tRJ\tRio de Janeiro\n");
        try {
            $resolver = new MunicipalityNameResolver($path);
            self::assertSame('3550308', $resolver->resolve('Sao Paulo', 'São Paulo'));
            self::assertSame('3550308', $resolver->resolve('São Paulo', 'SP'));
            self::assertSame('', $resolver->resolve('São Paulo', 'RJ'));
        } finally {
            unlink($path);
        }
    }

    public function testAmbiguousNameNeverSelectsAnArbitraryMunicipality(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ibge-');
        self::assertIsString($path);
        file_put_contents($path, "3550308\tSP\tSão Paulo\n3550309\tSP\tSão Paulo\n");
        try {
            self::assertSame('', (new MunicipalityNameResolver($path))->resolve('São Paulo', 'SP'));
        } finally {
            unlink($path);
        }
    }
}
