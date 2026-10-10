<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Sale {
    if (!class_exists(\App\Models\Sale\Invoice::class, false)) {
        class Invoice extends \App\Models\Document\Document
        {
        }
    }
}
