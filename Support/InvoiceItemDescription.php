<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Uses only the invoice item name as its fiscal service label; descriptions are unrelated.
 */
final class InvoiceItemDescription
{
    /** @param array<string,mixed> $item */
    public static function fromItem(array $item): string
    {
        $text = (string) ($item['name'] ?? '');

        return trim(str_replace(
            ['\r\n', '\n', '\r', "\r\n", "\r"],
            ["\n", "\n", "\n", "\n", "\n"],
            $text,
        ));
    }
}
