<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Canonical LC 116 service-list item representation used by the module.
 *
 * The module persists and compares LC 116 items as four digits, preserving
 * leading zeroes (for example, 1.07 -> 0107).
 */
final class Lc116Code
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $input = trim((string) $value);

        if ($input === '') {
            return '';
        }

        // UI values may be namespaced to distinguish catalog options.
        if (str_starts_with(strtolower($input), 'lc:')) {
            $input = substr($input, 3);
        }

        // Prefer the official human notation NN.NN / N.NN when present at
        // the beginning of the value. This also accepts labels such as
        // "1.07 - Suporte técnico".
        if (preg_match('/(?:^|\D)(\d{1,2})\s*\.\s*(\d{2})(?:\D|$)/u', $input, $matches) === 1) {
            $major = str_pad($matches[1], 2, '0', STR_PAD_LEFT);

            return $major . $matches[2];
        }

        // Canonical persisted form.
        if (preg_match('/^\d{4}$/', $input) === 1) {
            return $input;
        }

        // Compatibility for legacy rows such as "107", documented in #195.
        if (preg_match('/^[1-9]\d{2}$/', $input) === 1) {
            return '0' . $input;
        }

        return '';
    }
}
