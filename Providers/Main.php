<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Support\ServiceProvider as Provider;
use Modules\Nfse\Console\Commands\ProvisionTestUser;
use Modules\Nfse\Listeners\OverrideInvoiceEmailRoute;
use Modules\Nfse\Support\EmailTemplateSynchronizer;

class Main extends Provider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadTranslations();
        $this->loadViews();
        $this->loadMigrations();
        $this->registerInvoiceSendFlowOverride();
        $this->syncEmailTemplates();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->loadRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionTestUser::class,
            ]);
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

    protected function syncEmailTemplates(): void
    {
        (new EmailTemplateSynchronizer())->sync();
    }

    protected function registerInvoiceSendFlowOverride(): void
    {
        $this->app->make('view')->composer('sales.invoices.show', function ($view): void {
            $this->app->make(OverrideInvoiceEmailRoute::class)->overrideForInvoice(
                $view->getData()['invoice'] ?? null
            );
        });
    }
}
