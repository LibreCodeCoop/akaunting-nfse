<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Setting {
    if (!class_exists(\App\Models\Setting\EmailTemplate::class, false)) {
        class EmailTemplate
        {
            public static ?self $stubInstance = null;
            public static int $savedCount = 0;
            public string $subject = "";
            public string $body = "";
            public function save(): void
            {
                self::$savedCount++;
            } public static function alias(string $alias): object
            {
                return new class (self::$stubInstance) {
                    public function __construct(private ?\App\Models\Setting\EmailTemplate $t)
                    {
                    } public function first(): ?\App\Models\Setting\EmailTemplate
                    {
                        return $this->t;
                    }
                };
            }
        }
    }
}
