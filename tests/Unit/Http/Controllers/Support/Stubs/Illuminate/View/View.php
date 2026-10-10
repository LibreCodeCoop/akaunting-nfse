<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\View {
    if (!class_exists(\Illuminate\View\View::class, false)) {
        class View
        {
            public function __construct(public string $name, public array $data = [])
            {
            }
        }
    }
}
