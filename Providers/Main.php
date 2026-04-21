<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use App\Models\Common\Item as CoreItem;
use Illuminate\Support\ServiceProvider as Provider;
use Modules\Nfse\Console\Commands\ProvisionTestUser;
use Modules\Nfse\Listeners\OverrideInvoiceEmailRoute;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\EmailTemplateSynchronizer;
use Modules\Nfse\Support\Lc116Catalog;

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
        $this->registerItemFiscalProfileHooks();
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

    protected function registerItemFiscalProfileHooks(): void
    {
        CoreItem::saved(function (CoreItem $item): void {
            $request = request();

            if (!is_object($request) || !method_exists($request, 'input')) {
                return;
            }

            $rawServiceCode = $request->input('nfse_item_lista_servico', null);
            $rawNationalCode = $request->input('nfse_codigo_tributacao_nacional', null);

            if ($rawServiceCode === null && $rawNationalCode === null) {
                return;
            }

            $companyId = is_numeric($item->company_id ?? null) ? (int) $item->company_id : 0;
            $itemId = is_numeric($item->id ?? null) ? (int) $item->id : 0;

            if ($companyId <= 0 || $itemId <= 0) {
                return;
            }

            $serviceDigits = preg_replace('/\D+/', '', (string) $rawServiceCode) ?: '';
            $serviceCode = preg_match('/(\d{4})$/', $serviceDigits, $serviceCodeMatch)
                ? $serviceCodeMatch[1]
                : substr($serviceDigits, 0, 4);

            $nationalCode = preg_replace('/\D+/', '', (string) $rawNationalCode) ?: '';
            $nationalCode = $nationalCode !== ''
                ? str_pad(substr($nationalCode, 0, 6), 6, '0', STR_PAD_LEFT)
                : null;

            if ($serviceCode === '' && $nationalCode === null) {
                try {
                    ItemFiscalProfile::query()
                        ->where('company_id', $companyId)
                        ->where('item_id', $itemId)
                        ->delete();
                } catch (\Throwable) {
                    // Keep item save flow resilient.
                }

                return;
            }

            try {
                ItemFiscalProfile::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'item_id' => $itemId,
                    ],
                    [
                        'item_lista_servico' => $serviceCode !== '' ? $serviceCode : null,
                        'codigo_tributacao_nacional' => $nationalCode,
                    ]
                );
            } catch (\Throwable) {
                // Keep item save flow resilient.
            }
        });
    }
}
