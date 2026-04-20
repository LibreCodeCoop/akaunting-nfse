<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Abstracts\Http;

abstract class Controller
{
    public function middleware(string $middleware): object
    {
        return new class () {
            public function only(string ...$methods): self
            {
                return $this;
            }
        };
    }
}

namespace App\Abstracts;

class Notification
{
    /** @var array<string, mixed> */
    protected array $custom_mail = [];

    public function __construct()
    {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }
}

class Job
{
}

namespace Illuminate\Http;

class Request
{
    public function input(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

class JsonResponse
{
    /** @return array<string, mixed> */
    public function getData(bool $assoc = false): array
    {
        return [];
    }
}

class Response
{
}

class RedirectResponse
{
}

namespace Illuminate\Database\Eloquent;

class Model
{
    protected $table = '';

    protected $fillable = [];

    protected $casts = [];

    public bool $exists = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

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

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function query(): \Illuminate\Database\Eloquent\Builder
    {
        return new \Illuminate\Database\Eloquent\Builder();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public static function where(string $column, mixed $value): \Illuminate\Database\Eloquent\Builder
    {
        return new \Illuminate\Database\Eloquent\Builder();
    }

    /** @param array<string, mixed> $attributes
     *  @param array<string, mixed> $values */
    public static function updateOrCreate(array $attributes, array $values): static
    {
        return new static(array_merge($attributes, $values));
    }

    public static function saved(callable $callback): void
    {
    }

    public function belongsTo(string $related): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return new \Illuminate\Database\Eloquent\Relations\BelongsTo();
    }
}

namespace Illuminate\Database\Eloquent\Relations;

class BelongsTo
{
    public function __construct(mixed ...$args)
    {
    }
}

namespace Illuminate\Database\Eloquent;

class Builder
{
    public function __construct(mixed ...$args)
    {
    }

    public function where(string $column, mixed $value): static
    {
        return $this;
    }

    public function latest(string $column): static
    {
        return $this;
    }

    public function first(): ?Model
    {
        return null;
    }

    public function delete(): int
    {
        return 0;
    }
}

namespace App\Models\Document;

class Document
{
    public mixed $id = null;

    public mixed $type = null;

    public object $contact;

    public function __construct()
    {
        $this->contact = new class () {
            public function withPersons(): array
            {
                return [];
            }
        };
    }

    public static function findOrFail(mixed $id): static
    {
        $document = new static();
        $document->id = $id;

        return $document;
    }
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

namespace App\Models\Common;

class Item extends \Illuminate\Database\Eloquent\Model
{
}

namespace App\Models\Setting;

class EmailTemplate
{
}

namespace Illuminate\Support;

class Collection
{
    public function first(): mixed
    {
        return null;
    }
}

namespace Illuminate\Mail;

class Attachment
{
}

namespace Illuminate\Notifications\Messages;

class MailMessage
{
}

namespace Illuminate\Support\Facades;

class Notification
{
    public static function route(string $channel, mixed $route): object
    {
        return new class () {
            public function notify(mixed $notification): void
            {
            }
        };
    }
}

namespace App\Traits;

trait Emails
{
    public function sendEmail(mixed $job): mixed
    {
        return null;
    }
}

trait Documents
{
    public function storeDocumentPdfAndGetPath(mixed $document): string
    {
        return '';
    }
}

namespace Modules\Nfse\Providers;

function request(): mixed
{
    return new \Illuminate\Http\Request();
}

namespace {

    class StubView
    {
        public function render(): string
        {
            return '';
        }
    }

    class StubResponseFactory
    {
        public function json(array $data): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse();
        }
    }

    class StubFlasher
    {
        public function success(): void
        {
        }
    }

    function app(?string $abstract = null): object
    {
        return new class () {
            public function routesAreCached(): bool
            {
                return false;
            }

            public function make(string $abstract): object
            {
                return new class () {
                    public function composer(string|array $views, callable $callback): void
                    {
                    }

                    public function overrideForInvoice(mixed $invoice): void
                    {
                    }

                    public function servicePreview(mixed $invoice): \Illuminate\Http\JsonResponse
                    {
                        return new \Illuminate\Http\JsonResponse();
                    }
                };
            }
        };
    }

    function request(): \Illuminate\Http\Request
    {
        return new \Illuminate\Http\Request();
    }

    function setting(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    function trans(string $key, array $replace = []): string
    {
        return $key;
    }

    function trans_choice(string $key, int $number, array $replace = []): string
    {
        return $key;
    }

    function view(string $view, array $data = []): StubView
    {
        return new StubView();
    }

    function response(): StubResponseFactory
    {
        return new StubResponseFactory();
    }

    function route(string $name, mixed $parameters = null): string
    {
        return $name;
    }

    function user(): object
    {
        return new class () {
            public string $email = '';
        };
    }

    function config(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    function flash(string $message): StubFlasher
    {
        return new StubFlasher();
    }
}
