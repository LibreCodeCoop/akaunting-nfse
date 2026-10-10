<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\Http {
    if (!class_exists(\Illuminate\Http\UploadedFile::class, false)) {
        class UploadedFile
        {
            public function __construct(private string|false $realPath)
            {
            } public function getRealPath(): string|false
            {
                return $this->realPath;
            }
        }
    }
}
