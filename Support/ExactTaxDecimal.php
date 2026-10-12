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

    /**
     * NFS-e NT007 v1.0, section 2(c): vPis/vCofins use half-even rounding.
     * The caller must first establish the lawful base and rate independently.
     * Rates are percent units with up to six decimals; money is integer cents.
     */
    public static function percentOfCentsHalfEven(int $baseCents, string $percent): int
    {
        if ($baseCents < 0) {
            throw new \DomainException('Negative fiscal base requires a separate legal rule.');
        }

        $rate = self::rate($percent);
        $parts = explode('.', (string) $rate, 2);
        $whole = (int) $parts[0];
        $fraction = (int) str_pad($parts[1] ?? '', 6, '0');
        if ($whole > intdiv(PHP_INT_MAX - $fraction, 1000000)) {
            throw new \OverflowException('Percentage exceeds exact integer precision.');
        }

        $scaledPercent = ($whole * 1000000) + $fraction;
        if ($scaledPercent !== 0 && $baseCents > intdiv(PHP_INT_MAX, $scaledPercent)) {
            throw new \OverflowException('Rate calculation exceeds exact integer precision.');
        }

        $product = $baseCents * $scaledPercent;
        $divisor = 100000000;
        $cents = intdiv($product, $divisor);
        $remainder = $product % $divisor;
        if ($remainder * 2 > $divisor
            || ($remainder * 2 === $divisor && $cents % 2 !== 0)) {
            ++$cents;
        }

        return $cents;
    }

    public static function formatCents(int $cents): string
    {
        if ($cents === PHP_INT_MIN) {
            throw new \OverflowException('Cannot format minimum signed integer as positive magnitude.');
        }

        $positive = abs($cents);

        return ($cents < 0 ? '-' : '')
            . intdiv($positive, 100)
            . '.'
            . str_pad((string) ($positive % 100), 2, '0', STR_PAD_LEFT);
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
