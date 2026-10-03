<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Support\ServiceProvider as Provider;
use Modules\Nfse\Console\Commands\ProvisionTestUser;
use Modules\Nfse\Listeners\OverrideInvoiceEmailRoute;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\EmailTemplateSynchronizer;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NativeStreamTransport;

class Main extends Provider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadTranslations();
        $this->loadViews();
        $this->registerCoreItemViewOverrides();
        $this->loadMigrations();
        $this->registerInvoiceSendFlowOverride();
        $this->registerItemFiscalFieldInjection();
        $this->syncEmailTemplates();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->loadModuleVendorAutoload();
        $this->registerFiscalClientComposition();
        $this->loadRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionTestUser::class,
            ]);
        }
    }

    protected function registerFiscalClientComposition(): void
    {
        $this->app->bind(
            HttpTransportInterface::class,
            static fn (): HttpTransportInterface => new NativeStreamTransport(),
        );

        $this->app->bind(
            FiscalClientFactory::class,
            fn (): FiscalClientFactory => new FiscalClientFactory(
                transport: $this->app->make(HttpTransportInterface::class),
            ),
        );
    }

    protected function loadModuleVendorAutoload(): void
    {
        $moduleRoot = dirname(__DIR__);
        $autoloadPaths = [
            $moduleRoot . '/vendor/autoload.php',
            $moduleRoot . '/3rdparty/scoped/autoload.php',
        ];

        foreach ($autoloadPaths as $autoloadPath) {
            if (is_file($autoloadPath)) {
                require_once $autoloadPath;
            }
        }
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'nfse');
    }

    protected function registerCoreItemViewOverrides(): void
    {
        $viewFactory = $this->app->make('view');

        if (!is_object($viewFactory) || !method_exists($viewFactory, 'getFinder')) {
            return;
        }

        $finder = $viewFactory->getFinder();

        if (!is_object($finder) || !method_exists($finder, 'getPaths') || !method_exists($finder, 'setPaths')) {
            return;
        }

        $overridePath = __DIR__ . '/../Resources/overrides';
        $paths = $finder->getPaths();

        if (in_array($overridePath, $paths, true)) {
            return;
        }

        $finder->setPaths(array_merge([$overridePath], $paths));
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

    protected function registerItemFiscalFieldInjection(): void
    {
        $this->app->make('view')->composer(['common.items.create', 'common.items.edit'], function ($view): void {
            $item = $view->getData()['item'] ?? null;
            $itemId = is_object($item) && is_numeric($item->id ?? null) ? (int) $item->id : 0;
            $companyId = is_object($item) && is_numeric($item->company_id ?? null) ? (int) $item->company_id : 0;

            if ($companyId <= 0 && function_exists('company_id')) {
                $companyId = (int) company_id();
            }

            $profile = null;

            if ($itemId > 0 && $companyId > 0) {
                try {
                    $profile = ItemFiscalProfile::query()
                        ->where('company_id', $companyId)
                        ->where('item_id', $itemId)
                        ->first();
                } catch (\Throwable) {
                    $profile = null;
                }
            }

            $catalog = (new Lc116Catalog())->search(null, 400);

            $view->with('nfseLc116Catalog', $catalog);
            $view->with('nfseItemFiscalProfile', $profile);
        });
    }


}
