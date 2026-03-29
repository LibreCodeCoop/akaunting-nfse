<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Abstracts\Http;

abstract class Controller
{
}

namespace Illuminate\Database\Eloquent;

class Model
{
    protected $table = '';

    protected $fillable = [];

    protected $casts = [];

    public function getFillable(): array
    {
        return $this->fillable;
    }

    public function getCasts(): array
    {
        return $this->casts;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function __get(string $key): mixed
    {
        return null;
    }

    public function belongsTo(string $related): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return new \Illuminate\Database\Eloquent\Relations\BelongsTo();
    }
}

namespace Illuminate\Database\Eloquent\Relations;

class BelongsTo
{
}

namespace App\Models\Document;

class Document
{
}

namespace Illuminate\Support;

class ServiceProvider
{
    public object $app;

    public function __construct()
    {
        $this->app = new class () {
            public function runningInConsole(): bool
            {
                return false;
            }
        };
    }

    public function commands(array $commands): void
    {
    }

    public function loadViewsFrom(string $path, string $namespace): void
    {
    }

    public function loadTranslationsFrom(string $path, string $namespace): void
    {
    }

    public function loadMigrationsFrom(string $path): void
    {
    }

    public function loadRoutesFrom(string $path): void
    {
    }
}

namespace Illuminate\Console;

class Command
{
}

namespace Illuminate\Foundation\Support\Providers;

class EventServiceProvider extends \Illuminate\Support\ServiceProvider
{
}
