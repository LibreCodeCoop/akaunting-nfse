<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/** Strict observed DPS opSimpNac, without a guessed taxpayer status. */
final class SimplesOptionCode
{
    public static function observed(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $code = trim((string) $value);

        return in_array($code, ['1', '2', '3'], true) ? (int) $code : null;
    }
}
