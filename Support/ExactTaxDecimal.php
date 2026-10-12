<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Parses observed decimal money without binary-float rounding.
 * Sub-cent amounts need an explicit fiscal rounding rule; never truncate.
 */
final class ExactTaxDecimal
{
    public static function cents(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new \DomainException('Observed tax amount must be a decimal string or integer.');
        }

        $raw = trim((string) $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $raw, $match) !== 1) {
            throw new \DomainException('Invalid observed tax decimal amount.');
        }

        $fraction = $match[3] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new \DomainException('Tax amount contains nonzero sub-cent digits; rule-specific rounding required.');
        }

        $whole = (int) $match[2];
        if ($whole > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new \OverflowException('Observed tax amount exceeds integer-cent capacity.');
        }

        $minor = (int) substr(str_pad($fraction, 2, '0'), 0, 2);
        $cents = ($whole * 100) + $minor;

        return $match[1] === '-' ? -$cents : $cents;
    }

    public static function rate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new \DomainException('Observed tax rate must be a decimal string or integer.');
        }

        $raw = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,6})?$/D', $raw) !== 1) {
            throw new \DomainException('Observed tax rate has unsupported precision.');
        }

        return $raw;
    }
}
