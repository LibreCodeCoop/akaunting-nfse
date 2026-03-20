<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Support\ServiceProvider as Provider;

class Main extends Provider
{
    private const NFSE_PHP_AUTOLOAD = __DIR__ . '/../../../packages/librecodeoop/nfse-php/vendor/autoload.php';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadTranslations();
        $this->loadViews();
        $this->loadMigrations();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerNfsePhpAutoload();
        $this->loadRoutes();
    }

    protected function registerNfsePhpAutoload(): void
    {
        if (is_file(self::NFSE_PHP_AUTOLOAD)) {
            require_once self::NFSE_PHP_AUTOLOAD;
        }
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'nfse');
    }

    protected function loadTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'nfse');
    }

    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    protected function loadRoutes(): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin.php');
    }
}
