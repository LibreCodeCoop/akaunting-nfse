<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Tests\TestCase;

final class Lc116CodeTest extends TestCase
{
    /**
     * @dataProvider canonicalInputs
     */
    public function testNormalizesSupportedRepresentations(mixed $input, string $expected): void
    {
        self::assertSame($expected, Lc116Code::normalize($input));
    }

    /**
     * @return array<string, array{0:mixed,1:string}>
     */
    public static function canonicalInputs(): array
    {
        return [
            'canonical' => ['0107', '0107'],
            'official-human' => ['01.07', '0107'],
            'short-human' => ['1.07', '0107'],
            'legacy-three-digits' => ['107', '0107'],
            'ui-prefix' => ['lc:0107', '0107'],
            'human-label' => ['1.07 - Suporte técnico', '0107'],
            'two-digit-major' => ['14.01', '1401'],
            'empty' => ['', ''],
            'null' => [null, ''],
            'ambiguous-two-digits' => ['17', ''],
            'ambiguous-zero-prefixed-three-digits' => ['001', ''],
            'too-long' => ['010701', ''],
            'prefixed-label' => ['LC 116 / 01.07', '0107'],
        ];
    }
}
