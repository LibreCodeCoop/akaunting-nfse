<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Sale {
    if (!class_exists(\App\Models\Sale\FakeCollection::class, false)) {
        class FakeCollection
                {
                    public function __construct(private array $items)
                    {
                    } public function pluck(string $key): self
                    {
                        return new self(array_map(static fn (array|object $item): mixed => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $this->items));
                    } public function toArray(): array
                    {
                        return $this->items;
                    }
                }
    }
}
