<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Modules\Nfse\Support\Testing\SyntheticPkcs12Factory;

final class SyntheticPkcs12
{
    /**
     * @return array{path:string,password:string}
     */
    public static function create(string $directory, string $password = 'nfse-test-password'): array
    {
        return SyntheticPkcs12Factory::create(
            directory: $directory,
            password: $password,
        );
    }
}
