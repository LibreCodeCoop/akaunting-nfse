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
}

namespace Illuminate\Support;

class ServiceProvider
{
}

namespace Illuminate\Foundation\Support\Providers;

class EventServiceProvider extends \Illuminate\Support\ServiceProvider
{
}
