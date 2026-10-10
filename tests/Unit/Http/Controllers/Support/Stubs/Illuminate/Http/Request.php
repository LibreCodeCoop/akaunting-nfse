<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\Http {
    if (!class_exists(\Illuminate\Http\Request::class, false)) {
        class Request
        {
            public function __construct(private array $inputs = [], private array $files = [], private array $serverVars = [])
            {
            } public static function create(string $uri, string $method = 'GET', array $parameters = []): static
            {
                return new static($parameters);
            } public function validate(array $rules): void
            {
            } public function input(string $key, mixed $default = null): mixed
            {
                return $this->inputs[$key] ?? $default;
            } public function has(string $key): bool
            {
                return array_key_exists($key, $this->inputs);
            } public function boolean(string $key, bool $default = false): bool
            {
                if (!array_key_exists($key, $this->inputs)) {
                    return $default;
                } return (bool)(int)$this->inputs[$key];
            } public function file(string $key): mixed
            {
                return $this->files[$key] ?? null;
            } public function query(string $key, mixed $default = null): mixed
            {
                return $this->inputs[$key] ?? $default;
            } public function header(string $key, mixed $default = null): mixed
            {
                $server = strtoupper(str_replace('-', '_', $key));
                return $this->serverVars['HTTP_' . $server] ?? $this->serverVars[$server] ?? $default;
            } public function isXmlHttpRequest(): bool
            {
                return ($this->serverVars['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
            }
        }
    }
}
