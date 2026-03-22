<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!class_exists(\App\Abstracts\Http\Controller::class, true)) {
        eval('namespace App\\Abstracts\\Http; abstract class Controller {}');
    }

    if (!class_exists(\Illuminate\Http\Request::class, false)) {
        eval('namespace Illuminate\\Http; class Request { public function __construct(private array $inputs = [], private array $files = []) {} public function validate(array $rules): void {} public function input(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; } public function file(string $key): mixed { return $this->files[$key] ?? null; } public function query(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; } }');
    }

    if (!class_exists(\Illuminate\Http\UploadedFile::class, false)) {
        eval('namespace Illuminate\\Http; class UploadedFile { public function __construct(private string|false $realPath) {} public function getRealPath(): string|false { return $this->realPath; } }');
    }

    if (!class_exists(\Illuminate\Http\RedirectResponse::class, false)) {
        eval('namespace Illuminate\\Http; class RedirectResponse { public bool $withInputCalled = false; public array $flash = []; public ?string $route = null; public ?string $target = null; public function withInput(): self { $this->withInputCalled = true; return $this; } public function with(string $key, mixed $value): self { $this->flash[$key] = $value; return $this; } }');
    }

    if (!class_exists(\Illuminate\Http\JsonResponse::class, false)) {
        eval('namespace Illuminate\\Http; class JsonResponse { public function __construct(public array $payload = [], public int $status = 200) {} public function getData(bool $assoc = false): object|array { return $assoc ? $this->payload : (object) $this->payload; } public function getStatusCode(): int { return $this->status; } }');
    }

    if (!class_exists(\Illuminate\View\View::class, false)) {
        eval('namespace Illuminate\\View; class View { public function __construct(public string $name, public array $data = []) {} }');
    }
}

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationState
    {
        /** @var array<string, mixed> */
        public static array $settings = [];

        /** @var array<string, string> */
        public static array $translations = [];

        public static int $savedCount = 0;

        public static string $storageRoot = '';

        public static function reset(): void
        {
            self::$settings = [];
            self::$translations = [];
            self::$savedCount = 0;
            self::$storageRoot = sys_get_temp_dir() . '/nfse-controller-isolation-test-' . uniqid('', true);

            if (!is_dir(self::$storageRoot)) {
                mkdir(self::$storageRoot, 0o777, true);
            }
        }
    }

    final class ControllerIsolationRedirector
    {
        public function back(): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'back';

            return $response;
        }

        public function route(string $name): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'route';
            $response->route = $name;

            return $response;
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

    if (!function_exists(__NAMESPACE__ . '\\redirect')) {
        function redirect(): ControllerIsolationRedirector
        {
            return new ControllerIsolationRedirector();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\trans')) {
        function trans(string $key): string
        {
            return ControllerIsolationState::$translations[$key] ?? $key;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\storage_path')) {
        function storage_path(string $path = ''): string
        {
            return rtrim(ControllerIsolationState::$storageRoot, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\view')) {
        function view(string $name, array $data = []): \Illuminate\View\View
        {
            return new \Illuminate\View\View($name, $data);
        }
    }
}
