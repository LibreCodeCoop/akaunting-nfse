<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\InvoiceItemDescription;

/**
 * Pure rules for composing service descriptions without framework dependencies.
 */
final class FiscalDescriptionComposer
{
    /** @param list<array<string,mixed>> $items */
    public function group(array $items, ?string $additional = null, ?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }

        $descriptions = [];

        foreach ($items as $item) {
            $description = InvoiceItemDescription::fromItem($item);

            if ($description !== '') {
                $descriptions[] = $description;
            }
        }

        return $this->joinDistinct([implode(' | ', $descriptions), $additional]);
    }

    public function additional(?string $notes, ?string $defaultDescription): string
    {
        return $this->joinDistinct([$notes, $defaultDescription]);
    }

    /**
     * @param list<string> $lineItems
     * @param list<string> $fallbackNames
     */
    public function invoice(
        ?string $invoiceDescription,
        array $lineItems,
        array $fallbackNames,
        ?string $notes,
        ?string $defaultDescription,
        ?string $customDescription,
        string $fallbackLabel,
    ): string {
        if ($customDescription !== null) {
            return $customDescription;
        }

        if ($defaultDescription !== null || $notes !== null) {
            $service = $this->normalize($invoiceDescription);

            if ($service === null && $lineItems !== []) {
                $service = $this->normalize(implode(' | ', $lineItems));
            }

            if ($service === null) {
                $service = $this->normalize(implode(' | ', $fallbackNames));
            }

            return $this->joinDistinct([$service, $notes, $defaultDescription]);
        }

        if ($lineItems !== []) {
            return implode(' | ', $lineItems);
        }

        return implode(' | ', $fallbackNames)
            ?: $invoiceDescription
            ?: $fallbackLabel;
    }

    /** @param list<?string> $parts */
    private function joinDistinct(array $parts): string
    {
        $unique = [];

        foreach ($parts as $part) {
            $text = $this->normalize($part);

            if ($text !== null && !in_array($text, $unique, true)) {
                $unique[] = $text;
            }
        }

        return implode("\n\n", $unique);
    }

    private function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $value = trim(str_replace(
            ['\\r\\n', '\\n', '\\r', "\r\n", "\r"],
            ["\n", "\n", "\n", "\n", "\n"],
            $text,
        ));

        return $value !== '' ? $value : null;
    }
}
