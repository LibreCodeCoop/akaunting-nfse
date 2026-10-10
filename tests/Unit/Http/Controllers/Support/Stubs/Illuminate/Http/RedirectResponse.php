<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\Http {
    if (!class_exists(\Illuminate\Http\RedirectResponse::class, false)) {
        class RedirectResponse
        {
            public bool $withInputCalled = false;
            public array $flash = [];
            public ?string $route = null;
            public ?string $target = null;
            public array $parameters = [];
            public function withInput(): self
            {
                $this->withInputCalled = true;
                return $this;
            } public function with(string $key, mixed $value): self
            {
                $this->flash[$key] = $value;
                return $this;
            } public function getTargetUrl(): string
            {
                if ($this->route !== null) {
                    return 'route://' . $this->route;
                } return 'back://';
            }
        }
    }
}
