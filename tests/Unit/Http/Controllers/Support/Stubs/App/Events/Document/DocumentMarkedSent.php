<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Events\Document {
    if (!class_exists(\App\Events\Document\DocumentMarkedSent::class, false)) {
        class DocumentMarkedSent
        {
            public function __construct(public mixed $document)
            {
            }
        }
    }
}
