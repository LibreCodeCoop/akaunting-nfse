<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Abstracts\Http {
    if (!class_exists(\App\Abstracts\Http\Controller::class, true)) {
        abstract class Controller
        {
        }
    }
}
