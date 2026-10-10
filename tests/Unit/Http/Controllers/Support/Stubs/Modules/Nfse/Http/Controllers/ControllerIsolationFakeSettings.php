<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationFakeSettings
    {
        public function forget(string $key): void
        {
            unset(ControllerIsolationState::$settings[$key]);
        }

        public function save(): void
        {
            ControllerIsolationState::$savedCount++;
        }
    }
}
