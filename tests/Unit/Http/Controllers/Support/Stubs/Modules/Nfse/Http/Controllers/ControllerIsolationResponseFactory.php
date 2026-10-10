<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationResponseFactory
    {
        public function json(array $payload, int $status = 200): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse($payload, $status);
        }
    }
}
