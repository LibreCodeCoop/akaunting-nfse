<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!class_exists(\App\Abstracts\Http\Controller::class, true)) {
        eval('namespace App\\Abstracts\\Http; abstract class Controller {}');
    }
}

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationState
    {
        /** @var array<string, mixed> */
        public static array $settings = [];

        public static int $savedCount = 0;

        public static string $storageRoot = '';

        public static function reset(): void
        {
            self::$settings = [];
            self::$savedCount = 0;
            self::$storageRoot = sys_get_temp_dir() . '/nfse-controller-isolation-test-' . uniqid('', true);

            if (!is_dir(self::$storageRoot)) {
                mkdir(self::$storageRoot, 0o777, true);
            }
        }
    }

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

    if (!function_exists(__NAMESPACE__ . '\\setting')) {
        function setting(?string $key = null, mixed $default = null): mixed
        {
            if ($key === null) {
                return new ControllerIsolationFakeSettings();
            }

            if ($key === 'nfse') {
                $prefix = 'nfse.';
                $values = [];

                foreach (ControllerIsolationState::$settings as $settingKey => $value) {
                    if (str_starts_with($settingKey, $prefix)) {
                        $values[substr($settingKey, strlen($prefix))] = $value;
                    }
                }

                return $values === [] ? $default : $values;
            }

            return ControllerIsolationState::$settings[$key] ?? $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\storage_path')) {
        function storage_path(string $path = ''): string
        {
            return rtrim(ControllerIsolationState::$storageRoot, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
        }
    }
}
