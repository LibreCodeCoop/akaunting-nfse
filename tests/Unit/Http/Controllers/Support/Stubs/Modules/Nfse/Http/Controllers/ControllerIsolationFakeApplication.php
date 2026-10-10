<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers {
    if (!class_exists(\Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication::class, false)) {
        final class ControllerIsolationFakeApplication
        {
            public function basePath(string $path = ""): string
            {
                return rtrim(ControllerIsolationState::$storageRoot, "/") . ($path !== "" ? "/" . ltrim($path, "/") : "");
            }
        }
    }
}
