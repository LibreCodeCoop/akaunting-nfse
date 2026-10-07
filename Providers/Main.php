<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider as Provider;
use Modules\Nfse\Application\ItemFiscalProfileValidator;
use Modules\Nfse\Application\ItemFiscalValidationSummary;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Console\Commands\DiagnoseMunicipalParameters;
use Modules\Nfse\Console\Commands\ProvisionTestHarness;
use Modules\Nfse\Console\Commands\ProvisionTestUser;
use Modules\Nfse\Console\Commands\SyncAdn;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\EmailTemplateSynchronizer;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\ItemMunicipalValidationResolver;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\NfseRuntimeContextFactory;
use Modules\Nfse\Support\Testing\DeterministicFiscalHttpTransport;
use Modules\Nfse\Support\Testing\EnvironmentFixtureSecretStore;
use Modules\Nfse\Support\Testing\FiscalTestHarnessConfig;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NativeStreamTransport;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

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
        $this->registerNativeInvoiceBulkAction();
        $this->registerNativeInvoiceFiscalPanel();
        $this->registerNativeInvoiceFiscalListStatus();
        $this->registerItemFiscalFieldInjection();
        $this->registerItemFiscalListValidation();
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
                ProvisionTestHarness::class,
                ProvisionTestUser::class,
                SyncAdn::class,
                DiagnoseMunicipalParameters::class,
            ]);
        }
    }

    protected function registerFiscalClientComposition(): void
    {
        $testHarnessEnabled = FiscalTestHarnessConfig::enabled(
            (string) $this->app->environment(),
            (\getenv('NFSE_TEST_HARNESS') ?: false),
        );

        if ($testHarnessEnabled) {
            $this->app->bind(
                HttpTransportInterface::class,
                static fn (): HttpTransportInterface => new DeterministicFiscalHttpTransport(),
            );

            $this->app->bind(
                SecretStoreInterface::class,
                static fn (): SecretStoreInterface => new EnvironmentFixtureSecretStore(),
            );
        } else {
            $this->app->bind(
                HttpTransportInterface::class,
                static fn (): HttpTransportInterface => new NativeStreamTransport(),
            );

            $this->app->bind(
                SecretStoreInterface::class,
                static function (): SecretStoreInterface {
                    $config = VaultConfig::secretStoreConfig();

                    return new OpenBaoSecretStore(
                        addr: $config['addr'],
                        mount: $config['mount'],
                        token: $config['token'],
                        roleId: $config['roleId'],
                        secretId: $config['secretId'],
                    );
                },
            );
        }

        $this->app->bind(
            NfseRuntimeContextFactory::class,
            static fn (): NfseRuntimeContextFactory => new NfseRuntimeContextFactory(),
        );

        $this->app->bind(
            FiscalClientFactory::class,
            fn (): FiscalClientFactory => new FiscalClientFactory(
                transport: $this->app->make(HttpTransportInterface::class),
                runtimeContextFactory: $this->app->make(NfseRuntimeContextFactory::class),
                secretStoreFactory: fn (): SecretStoreInterface => $this->app->make(SecretStoreInterface::class),
            ),
        );

        $this->app->bind(
            BulkEmissionUnitIssuerInterface::class,
            fn (): BulkEmissionUnitIssuerInterface => new AutomaticInvoiceFiscalIssuer(
                clientFactory: $this->app->make(FiscalClientFactory::class),
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

    protected function registerNativeInvoiceBulkAction(): void
    {
        config([
            'type.document.invoice.bulk_actions' => \Modules\Nfse\BulkActions\Invoices::class,
        ]);
    }

    protected function registerNativeInvoiceFiscalPanel(): void
    {
        $this->app->make('view')->composer('sales.invoices.show', function ($view): void {
            $invoice = $view->getData()['invoice'] ?? null;

            if (!is_object($invoice) || ($invoice->type ?? '') !== 'invoice') {
                return;
            }

            $invoiceId = is_numeric($invoice->id ?? null) ? (int) $invoice->id : 0;

            if ($invoiceId <= 0) {
                return;
            }

            $viewFactory = $this->app->make('view');
            $request = $this->app->make('request');
            $renderOnceKey = 'nfse.native_invoice_fiscal_panel.' . $invoiceId;

            if ($request->attributes->get($renderOnceKey, false) === true) {
                return;
            }

            $request->attributes->set($renderOnceKey, true);

            try {
                $receipts = NfseReceipt::query()
                    ->where('invoice_id', $invoiceId)
                    ->latest('id')
                    ->get();
            } catch (\Throwable) {
                $receipts = new \Illuminate\Support\Collection();
            }

            $receipt = $receipts->first();
            $postEmissionStatus = $receipt instanceof NfseReceipt
                ? (new PostEmissionState())->snapshot($receipt)
                : null;
            $authorizedXmlAvailable = false;

            if ($receipt instanceof NfseReceipt) {
                try {
                    $payload = $receipt->payload()->getResults();
                    $authorizedXmlAvailable = is_object($payload)
                        && trim((string) ($payload->authorized_xml ?? '')) !== '';
                } catch (\Throwable) {
                    $authorizedXmlAvailable = false;
                }
            }

            $content = view('nfse::invoices.partials.native-fiscal-panel', [
                'invoice' => $invoice,
                'receipt' => $receipt,
                'receipts' => $receipts,
                'postEmissionStatus' => $postEmissionStatus,
                'authorizedXmlAvailable' => $authorizedXmlAvailable,
            ])->render();

            $modalTrigger = view('nfse::invoices.partials.native-fiscal-modal-trigger', [
                'invoice' => $invoice,
            ])->render();

            $viewFactory->startPush('timeline_send_body_button_email_start', $modalTrigger);

            // Akaunting yields status_message_end both from the outer document
            // content view and from the nested status message component. Using
            // that stack duplicates the fiscal panel on draft invoices.
            //
            // create_start is the next native extension point after the status
            // area and is yielded exactly once by the document content view.
            $viewFactory->startPush('create_start', $content);

            $viewFactory->startPush(
                'body_end',
                view('nfse::modals.invoices.partials.issue-modal-script')->render(),
            );
            $viewFactory->startPush(
                'body_end',
                view('nfse::invoices.partials.post-emission-status-script')->render(),
            );
        });
    }

    protected function registerNativeInvoiceFiscalListStatus(): void
    {
        $this->app->make('view')->composer('sales.invoices.index', function ($view): void {
            $invoices = $view->getData()['invoices'] ?? null;

            if (!is_object($invoices) || !method_exists($invoices, 'getCollection')) {
                return;
            }

            $invoiceIds = $invoices->getCollection()
                ->map(static fn ($invoice): int => is_numeric($invoice->id ?? null) ? (int) $invoice->id : 0)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            if ($invoiceIds === []) {
                return;
            }

            $statuses = [];

            foreach ($invoiceIds as $invoiceId) {
                try {
                    $receipt = NfseReceipt::query()
                        ->where('invoice_id', $invoiceId)
                        ->latest('id')
                        ->first();
                } catch (\Throwable) {
                    $receipt = null;
                }
                $status = is_object($receipt) ? trim((string) ($receipt->status ?? '')) : 'pending';

                if ($status === '') {
                    $status = 'pending';
                }

                $knownStatus = in_array(
                    $status,
                    ['pending', 'processing', 'emitted', 'cancelled', 'substituted'],
                    true,
                ) ? $status : 'unknown';

                $statuses[$invoiceId] = [
                    'status' => $knownStatus,
                    'label' => trans('nfse::general.native_invoice.status_' . $knownStatus),
                    'url' => route('invoices.show', $invoiceId),
                ];
            }

            $content = view('nfse::invoices.partials.native-list-status-script', [
                'nfseInvoiceFiscalStatuses' => $statuses,
            ])->render();

            $this->app->make('view')->startPush('body_end', $content);
        });
    }

    protected function registerItemFiscalListValidation(): void
    {
        $this->app->make('view')->composer('common.items.index', function ($view): void {
            $items = $view->getData()['items'] ?? null;

            if (!is_object($items) || !method_exists($items, 'getCollection')) {
                return;
            }

            $collection = $items->getCollection();
            $itemIds = $collection
                ->map(static fn ($item): int => is_numeric($item->id ?? null) ? (int) $item->id : 0)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            if ($itemIds === []) {
                return;
            }

            $companyId = function_exists('company_id') ? (int) company_id() : 0;
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $bindings = $itemIds;
            $sql = 'SELECT item_id, item_lista_servico, codigo_tributacao_nacional'
                . ' FROM nfse_item_fiscal_profiles'
                . ' WHERE item_id IN (' . $placeholders . ')';

            if ($companyId > 0) {
                $sql .= ' AND company_id = ?';
                $bindings[] = $companyId;
            }

            $profiles = collect(DB::select($sql, $bindings))->keyBy('item_id');
            $validator = new ItemFiscalProfileValidator();
            $summary = new ItemFiscalValidationSummary();
            $municipalResolver = new ItemMunicipalValidationResolver();
            $municipalByItem = $municipalResolver->resolveMany(
                itemNationalCodes: array_reduce(
                    $itemIds,
                    static function (array $codes, int $itemId) use ($profiles): array {
                        $profile = $profiles->get($itemId);

                        if (is_object($profile)) {
                            $codes[$itemId] = (string) ($profile->codigo_tributacao_nacional ?? '');
                        }

                        return $codes;
                    },
                    [],
                ),
                companyId: $companyId,
                municipioIbge: trim((string) setting('nfse.municipio_ibge', '')),
                sandboxMode: filter_var(setting('nfse.sandbox_mode', true), FILTER_VALIDATE_BOOL),
            );
            $validation = [];

            foreach ($itemIds as $itemId) {
                $profile = $profiles->get($itemId);
                $result = $validator->validate(
                    is_object($profile) ? (string) ($profile->item_lista_servico ?? '') : null,
                    is_object($profile) ? (string) ($profile->codigo_tributacao_nacional ?? '') : null,
                );

                if (isset($municipalByItem[$itemId])) {
                    $result = $summary->combine($result, $municipalByItem[$itemId]);
                }

                $validation[$itemId] = [
                    'status' => $result['status'],
                    'label' => trans('nfse::general.items.validation.status_' . $result['status']),
                    'url' => route('items.edit', $itemId) . '#nfse-fiscal-fields',
                ];
            }

            $content = view('nfse::items.partials.list-validation-script', [
                'nfseItemListValidation' => $validation,
            ])->render();

            $this->app->make('view')->startPush('body_end', $content);
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
            $validation = (new ItemFiscalProfileValidator())->validate(
                is_object($profile) ? (string) ($profile->item_lista_servico ?? '') : null,
                is_object($profile) ? (string) ($profile->codigo_tributacao_nacional ?? '') : null,
            );

            if ($itemId > 0 && $companyId > 0 && is_object($profile)) {
                $municipal = (new ItemMunicipalValidationResolver())->resolve(
                    itemId: $itemId,
                    companyId: $companyId,
                    nationalCode: (string) ($profile->codigo_tributacao_nacional ?? ''),
                    municipioIbge: trim((string) setting('nfse.municipio_ibge', '')),
                    sandboxMode: filter_var(setting('nfse.sandbox_mode', true), FILTER_VALIDATE_BOOL),
                );
                $validation = (new ItemFiscalValidationSummary())->combine($validation, $municipal);
            }

            $view->with('nfseLc116Catalog', $catalog);
            $view->with('nfseItemFiscalProfile', $profile);
            $view->with('nfseItemFiscalValidation', $validation);
        });
    }


}
