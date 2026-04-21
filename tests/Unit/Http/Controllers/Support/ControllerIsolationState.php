<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!class_exists(\App\Abstracts\Http\Controller::class, true)) {
        eval('namespace App\\Abstracts\\Http; abstract class Controller {}');
    }

    if (!class_exists(\Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication::class, false)) {
        eval('namespace Modules\\Nfse\\Http\\Controllers; final class ControllerIsolationFakeApplication { public function basePath(string $path = ""): string { return rtrim(ControllerIsolationState::$storageRoot, "/") . ($path !== "" ? "/" . ltrim($path, "/") : ""); } }');
    }

    if (!function_exists('app')) {
        function app(): \Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication
        {
            return new \Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication();
        }
    }

    if (!class_exists(\Illuminate\Http\Request::class, false)) {
        eval('namespace Illuminate\\Http; class Request { public function __construct(private array $inputs = [], private array $files = [], private array $serverVars = []) {} public static function create(string $uri, string $method = \'GET\', array $parameters = []): static { return new static($parameters); } public function validate(array $rules): void {} public function input(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; } public function has(string $key): bool { return array_key_exists($key, $this->inputs); } public function boolean(string $key, bool $default = false): bool { if (!array_key_exists($key, $this->inputs)) { return $default; } return (bool)(int)$this->inputs[$key]; } public function file(string $key): mixed { return $this->files[$key] ?? null; } public function query(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; } public function header(string $key, mixed $default = null): mixed { $server = strtoupper(str_replace(\'-\', \'_\', $key)); return $this->serverVars[\'HTTP_\' . $server] ?? $this->serverVars[$server] ?? $default; } public function isXmlHttpRequest(): bool { return ($this->serverVars[\'HTTP_X_REQUESTED_WITH\'] ?? \'\') === \'XMLHttpRequest\'; } }');
    }

    if (!class_exists(\Illuminate\Http\UploadedFile::class, false)) {
        eval('namespace Illuminate\\Http; class UploadedFile { public function __construct(private string|false $realPath) {} public function getRealPath(): string|false { return $this->realPath; } }');
    }

    if (!class_exists(\Illuminate\Http\RedirectResponse::class, false)) {
        eval('namespace Illuminate\\Http; class RedirectResponse { public bool $withInputCalled = false; public array $flash = []; public ?string $route = null; public ?string $target = null; public array $parameters = []; public function withInput(): self { $this->withInputCalled = true; return $this; } public function with(string $key, mixed $value): self { $this->flash[$key] = $value; return $this; } public function getTargetUrl(): string { if ($this->route !== null) { return \'route://\' . $this->route; } return \'back://\'; } }');
    }

    if (!class_exists(\Illuminate\Http\JsonResponse::class, false)) {
        eval('namespace Illuminate\\Http; class JsonResponse { public function __construct(public array $payload = [], public int $status = 200) {} public function getData(bool $assoc = false): object|array { return $assoc ? $this->payload : (object) $this->payload; } public function getStatusCode(): int { return $this->status; } }');
    }

    if (!class_exists(\Illuminate\View\View::class, false)) {
        eval('namespace Illuminate\\View; class View { public function __construct(public string $name, public array $data = []) {} }');
    }

    if (!class_exists(\App\Events\Document\DocumentMarkedSent::class, false)) {
        eval('namespace App\\Events\\Document; class DocumentMarkedSent { public function __construct(public mixed $document) {} }');
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

    final class ControllerIsolationRedirector
    {
        public function back(): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'back';

            return $response;
        }

        public function route(string $name, mixed ...$parameters): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'route';
            $response->route = $name;
            $response->parameters = $parameters;

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

    final class ControllerIsolationFakeSession
    {
        public function flash(string $key, mixed $value): void
        {
            ControllerIsolationState::$sessionFlash[$key] = $value;
        }
    }

    final class ControllerIsolationResponseFactory
    {
        public function json(array $payload, int $status = 200): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse($payload, $status);
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
