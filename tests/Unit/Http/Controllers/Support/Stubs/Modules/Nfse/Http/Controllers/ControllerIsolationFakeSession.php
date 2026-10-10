<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationFakeSession
    {
        public function flash(string $key, mixed $value): void
        {
            ControllerIsolationState::$sessionFlash[$key] = $value;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return ControllerIsolationState::$sessionFlash[$key] ?? $default;
        }
    }
}
