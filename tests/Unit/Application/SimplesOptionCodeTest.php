<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\SimplesOptionCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimplesOptionCodeTest extends TestCase
{
    /** @return iterable<string,array{mixed,?int}> */
    public static function options(): iterable
    {
        yield 'non optant' => [1, 1];
        yield 'MEI' => ['2', 2];
        yield 'ME/EPP' => [' 3 ', 3];
        yield 'unknown' => [null, null];
        yield 'empty' => ['', null];
        yield 'leading zero' => ['02', null];
        yield 'boolean' => [true, null];
        yield 'array' => [[], null];
    }

    #[DataProvider('options')]
    public function testObservedOption(mixed $raw, ?int $expected): void
    {
        self::assertSame($expected, SimplesOptionCode::observed($raw));
    }
}
