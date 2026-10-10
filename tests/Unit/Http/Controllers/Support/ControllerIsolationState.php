<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Stubs/bootstrap.php';
}

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationState
    {
        /** @var array<string, mixed> */
        public static array $settings = [];

        /** @var array<string, string> */
        public static array $translations = [];

        public static int $savedCount = 0;

        /** @var array<string, mixed> */
        public static array $sessionFlash = [];

        public static string $storageRoot = '';

        public static function reset(): void
        {
            self::$settings = [];
            self::$translations = [];
            self::$savedCount = 0;
            self::$sessionFlash = [];
            self::$storageRoot = sys_get_temp_dir() . '/nfse-controller-isolation-test-' . uniqid('', true);

            if (!is_dir(self::$storageRoot)) {
                mkdir(self::$storageRoot, 0o777, true);
            }
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\setting')) {
        function setting(string|array|null $key = null, mixed $default = null): mixed
        {
            if (is_array($key)) {
                foreach ($key as $settingKey => $value) {
                    ControllerIsolationState::$settings[$settingKey] = $value;
                }

                return null;
            }

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

    if (!function_exists(__NAMESPACE__ . '\\session')) {
        function session(): ControllerIsolationFakeSession
        {
            return new ControllerIsolationFakeSession();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\redirect')) {
        function redirect(): ControllerIsolationRedirector
        {
            return new ControllerIsolationRedirector();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\back')) {
        function back(): \Illuminate\Http\RedirectResponse
        {
            return redirect()->back();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\response')) {
        function response(): ControllerIsolationResponseFactory
        {
            return new ControllerIsolationResponseFactory();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\event')) {
        function event(object $event): object
        {
            if ($event instanceof \App\Events\Document\DocumentMarkedSent && isset($event->document)) {
                $event->document->status = 'sent';
            }

            return $event;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\trans')) {
        function trans(string $key, array $replace = []): string
        {
            $translated = ControllerIsolationState::$translations[$key] ?? $key;

            foreach ($replace as $name => $value) {
                $translated = str_replace(':' . $name, (string) $value, $translated);
            }

            return $translated;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\storage_path')) {
        function storage_path(string $path = ''): string
        {
            return rtrim(ControllerIsolationState::$storageRoot, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\route')) {
        function route(string $name, mixed $parameters = []): string
        {
            if (is_object($parameters) && property_exists($parameters, 'id')) {
                $id = (string) $parameters->id;
            } elseif (is_array($parameters)) {
                $id = implode('/', array_values($parameters));
            } else {
                $id = (string) $parameters;
            }

            return 'route://' . $name . ($id !== '' ? '/' . $id : '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\view')) {
        function view(string $name, array $data = []): \Illuminate\View\View
        {
            return new \Illuminate\View\View($name, $data);
        }
    }
}
