<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\Http {
    if (!class_exists(\Illuminate\Http\JsonResponse::class, false)) {
        class JsonResponse
        {
            public function __construct(public array $payload = [], public int $status = 200)
            {
            } public function getData(bool $assoc = false): object|array
            {
                return $assoc ? $this->payload : (object) $this->payload;
            } public function getStatusCode(): int
            {
                return $this->status;
            }
        }
    }
}
