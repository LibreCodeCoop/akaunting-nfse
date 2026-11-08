<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use App\Http\Requests\Common\Contact as CoreContactRequest;
use App\Http\Requests\Common\Item as CoreItemRequest;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider as Provider;
use Modules\Nfse\Application\DpsIssuanceIdentity;
use Modules\Nfse\Application\DpsIssuanceIdentityContract;
use Modules\Nfse\Application\InvoiceFiscalDecision;
use Modules\Nfse\Application\ItemFiscalProfileValidator;
use Modules\Nfse\Application\ItemFiscalValidationSummary;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Console\Commands\DiagnoseMunicipalParameters;
use Modules\Nfse\Console\Commands\ProvisionTestHarness;
use Modules\Nfse\Console\Commands\ProvisionTestUser;
use Modules\Nfse\Console\Commands\SyncAdn;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Http\Requests\ValidatedFiscalContact;
use Modules\Nfse\Http\Requests\ValidatedFiscalItem;
use Modules\Nfse\Listeners\PersistContactFiscalProfile;
use Modules\Nfse\Listeners\PersistItemFiscalProfile;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Modules\Nfse\Support\AutomaticInvoiceFiscalIssuer;
use Modules\Nfse\Support\ContactFiscalProfileStore;
use Modules\Nfse\Support\EmailTemplateSynchronizer;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\InvoiceFiscalEventOverview;
use Modules\Nfse\Support\ItemMunicipalCompanyContext;
use Modules\Nfse\Support\ItemMunicipalValidationResolver;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\NfseCompanyCertificateStore;
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
        $this->registerNativeInvoiceFiscalSearch();
        $this->registerNativeInvoiceFiscalListStatus();
        $this->registerItemFiscalFieldInjection();
        $this->registerItemFiscalPersistence();
        $this->registerContactFiscalPersistence();
        $this->registerContactFiscalFieldInjection();
        $this->registerItemFiscalListValidation();
        $this->syncEmailTemplates();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->loadModuleVendorAutoload();
        $this->app->bind(CoreItemRequest::class, ValidatedFiscalItem::class);
        $this->app->bind(CoreContactRequest::class, ValidatedFiscalContact::class);
        $this->app->scoped(DpsIssuanceIdentityContract::class, function (): DpsIssuanceIdentityContract {
            // Synthetic authority is only available in disposable SQLite tests.
            // Production always requires the single Akaunting MySQL primary.
            if (FiscalTestHarnessConfig::enabled(
                (string) app()->environment(),
                getenv('NFSE_TEST_HARNESS'),
            ) && app()->environment('testing') && config('database.default') === 'sqlite') {
                return new DpsIssuanceIdentity(
                    coordinator: new \Modules\Nfse\Support\Testing\SyntheticDpsSeriesCoordinator(),
                );
            }

            return new DpsIssuanceIdentity();
        });
        $this->app->scoped(PersistItemFiscalProfile::class, PersistItemFiscalProfile::class);
        $this->app->scoped(PersistContactFiscalProfile::class, PersistContactFiscalProfile::class);
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

        // Profile metadata edits never need a Vault client. Certificate operations
        // still resolve the same shared SecretStore when they actually use it.
        $this->app->bind(
            NfseCompanyCertificateStore::class,
            fn (): NfseCompanyCertificateStore => new NfseCompanyCertificateStore(
                secrets: fn (): SecretStoreInterface => $this->app->make(SecretStoreInterface::class),
                diagnostics: $this->app->make(\Modules\Nfse\Support\VaultDiagnostics::class),
            ),
        );

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
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
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

            if (!$invoice instanceof Invoice || ($invoice->type ?? '') !== 'invoice') {
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
                    $payload = NfseReceiptPayload::query()
                        ->where('receipt_id', (int) $receipt->getKey())
                        ->first();
                    $authorizedXmlAvailable = $payload instanceof NfseReceiptPayload
                        && trim((string) $payload->getAttribute('authorized_xml')) !== '';
                } catch (\Throwable) {
                    $authorizedXmlAvailable = false;
                }
            }

            // Advisory calendar preview only. The actual outgoing DPS is
            // reassessed by EmissionAttemptJournal before its first POST.
            try {
                $fiscalEventGroups = (new InvoiceFiscalEventOverview())->forInvoice($invoice);
            } catch (\Throwable) {
                $fiscalEventGroups = [];
            }

            $decision = null;
            $effectiveDecision = null;
            $categoryPolicy = null;
            $decisionHistory = [];
            try {
                $companyId = function_exists('company_id') ? (int) company_id() : 0;
                if ($companyId > 0 && (int) $invoice->company_id === $companyId) {
                    $decisions = new InvoiceFiscalDecision();
                    $decision = $decisions->current($companyId, $invoiceId);
                    $effectiveDecision = $decisions->effective($invoice);
                    $categoryPolicy = (new \Modules\Nfse\Application\CategoryIssuancePolicy())
                        ->forInvoice($invoice);
                    $decisionHistory = $decisions->history($companyId, $invoiceId);
                }
            } catch (\Exception) {
                // Unmigrated deployments should not break the financial invoice.
            }

            $content = view('nfse::invoices.partials.native-fiscal-panel', [
                'invoice' => $invoice,
                'receipt' => $receipt,
                'receipts' => $receipts,
                'postEmissionStatus' => $postEmissionStatus,
                'authorizedXmlAvailable' => $authorizedXmlAvailable,
                'fiscalEventGroups' => $fiscalEventGroups,
                'fiscalDecision' => $decision,
                'effectiveFiscalDecision' => $effectiveDecision,
                'categoryIssuancePolicy' => $categoryPolicy,
                'fiscalDecisionHistory' => $decisionHistory,
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
            $viewFactory->startPush(
                'body_end',
                view('nfse::invoices.partials.receipt-actions-script')->render(),
            );
        });
    }

    protected function registerNativeInvoiceFiscalSearch(): void
    {
        // Document is shared with bills: add this virtual option only when
        // rendering the native commercial invoice page, not across all screens.
        $this->app->make('view')->composer('sales.invoices.index', static function (): void {
            // The rendered component uses Akaunting's legacy Sale\\Invoice search key,
            // while the query is built from the canonical Document model.
            $filter = [
                'translation' => 'nfse::general.ledger.filter_fiscal_status',
                'multiple' => true,
                'values' => [
                    'emitted' => 'nfse::general.ledger.status_emitted',
                    'processing' => 'nfse::general.ledger.status_processing',
                    'cancelled' => 'nfse::general.ledger.status_cancelled',
                    'substituted' => 'nfse::general.ledger.status_substituted',
                    'pending' => 'nfse::general.native_invoice.status_pending',
                    'absent' => 'nfse::general.ledger.status_absent',
                    'unknown' => 'nfse::general.ledger.status_unknown',
                    'do_not_issue' => 'nfse::general.invoice_decision.do_not_issue',
                    'not_applicable' => 'nfse::general.invoice_decision.not_applicable',
                ],
            ];
            config()->set('search-string.' . Invoice::class . '.columns.nfse_status', $filter);
            config()->set('search-string.App\\Models\\Sale\\Invoice.columns.nfse_status', $filter);
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

            $receiptStatuses = [];

            try {
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $rows = DB::select(
                    'SELECT receipt.invoice_id, receipt.status'
                    . ' FROM nfse_receipts AS receipt'
                    . ' INNER JOIN ('
                    . ' SELECT invoice_id, MAX(id) AS latest_id'
                    . ' FROM nfse_receipts'
                    . ' WHERE invoice_id IN (' . $placeholders . ')'
                    . ' GROUP BY invoice_id'
                    . ' ) AS latest ON latest.latest_id = receipt.id',
                    $invoiceIds,
                );

                foreach ($rows as $row) {
                    $receiptStatuses[(int) $row->invoice_id] = trim((string) $row->status);
                }
            } catch (\Throwable $exception) {
                // A failed fiscal lookup is not equivalent to an unissued NFS-e.
                // Keep the invoice listing available, but display unknown instead of pending.
                $receiptStatuses = array_fill_keys($invoiceIds, 'unknown');
                error_log('NFS-e fiscal status lookup failed: ' . $exception->getMessage());
            }

            $decisionsByInvoice = [];
            try {
                $companyId = function_exists('company_id') ? (int) company_id() : 0;
                if ($companyId > 0) {
                    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                    $bindings = [$companyId];
                    foreach ((array) $invoiceIds as $id) {
                        $bindings[] = (int) $id;
                    }
                    $decisionRows = DB::select(
                        'SELECT invoice_id, decision FROM nfse_invoice_decisions'
                        . ' WHERE company_id = ? AND invoice_id IN (' . $placeholders . ')'
                        . ' ORDER BY id DESC',
                        $bindings,
                    );
                    foreach ($decisionRows as $row) {
                        $id = (int) $row->invoice_id;
                        if (!isset($decisionsByInvoice[$id])) {
                            $decisionsByInvoice[$id] = (string) $row->decision;
                        }
                    }
                }
            } catch (\Exception) {
                // A missing migration must not corrupt the invoice list.
            }

            $statuses = [];
            $invoicesById = $invoices->getCollection()->keyBy('id');
            $categoryPolicyService = new \Modules\Nfse\Application\CategoryIssuancePolicy();

            foreach ($invoiceIds as $invoiceId) {
                $status = $receiptStatuses[$invoiceId] ?? 'pending';

                if (!isset($receiptStatuses[$invoiceId])) {
                    $manual = $decisionsByInvoice[$invoiceId] ?? null;
                    if (in_array($manual, ['do_not_issue', 'not_applicable'], true)) {
                        $status = $manual;
                    } elseif ($manual !== 'issue') {
                        $invoice = $invoicesById->get($invoiceId);
                        try {
                            $categoryPolicy = $invoice instanceof Invoice
                                ? $categoryPolicyService->forInvoice($invoice) : null;
                            if (($categoryPolicy['mode'] ?? '') === 'do_not_issue') {
                                $status = 'do_not_issue';
                            }
                        } catch (\Exception) {
                            $status = 'unknown';
                        }
                    }
                }

                if ($status === '') {
                    $status = 'pending';
                }

                $knownStatus = in_array(
                    $status,
                    ['pending', 'processing', 'emitted', 'cancelled', 'substituted', 'do_not_issue', 'not_applicable'],
                    true,
                ) ? $status : 'unknown';

                $statuses[$invoiceId] = [
                    'status' => $knownStatus,
                    'label' => in_array($knownStatus, ['do_not_issue', 'not_applicable'], true)
                        ? trans('nfse::general.invoice_decision.' . $knownStatus)
                        : trans('nfse::general.native_invoice.status_' . $knownStatus),
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
            if ($companyId <= 0) {
                return;
            }

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
            $municipalContext = (new ItemMunicipalCompanyContext())->forCompany($companyId);
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
                municipioIbge: $municipalContext['municipio_ibge'],
                sandboxMode: $municipalContext['sandbox_mode'],
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

    /**
     * Native before events carry the request; Eloquent saves run within the
     * native CreateItem/UpdateItem DB::transaction, not after its commit.
     */
    protected function registerItemFiscalPersistence(): void
    {
        Item::saving(static function (Item $item): void {
            /** @var PersistItemFiscalProfile $listener */
            $listener = app(PersistItemFiscalProfile::class);
            $listener->beforeSave($item);
        });

        Item::saved(static function (Item $item): void {
            /** @var PersistItemFiscalProfile $listener */
            $listener = app(PersistItemFiscalProfile::class);
            $listener->afterSave($item);
        });
    }

    protected function registerContactFiscalPersistence(): void
    {
        Contact::saving(static function (Contact $contact): void {
            app(PersistContactFiscalProfile::class)->beforeSave($contact);
        });
        Contact::saved(static function (Contact $contact): void {
            app(PersistContactFiscalProfile::class)->afterSave($contact);
        });
    }

    protected function registerContactFiscalFieldInjection(): void
    {
        $this->app->make('view')->composer('components.contacts.form.address', static function ($view): void {
            $data = $view->getData();
            if (($data['type'] ?? '') !== Contact::CUSTOMER_TYPE) {
                return;
            }
            // The native address component receives only "type": its Contact
            // is owned by the parent form component, not passed as a direct prop.
            $contact = $data['contact'] ?? null;
            if (!$contact instanceof Contact) {
                $parentModel = app('view')->getConsumableComponentData('model');
                $contact = $parentModel instanceof Contact ? $parentModel : null;
            }
            $profile = (new ContactFiscalProfileStore())->forContact($contact);
            $view->with('nfseMunicipalRegistration', $profile['municipal_registration']);
            $view->with('nfseLegalName', $profile['legal_name']);
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
                $municipalContext = (new ItemMunicipalCompanyContext())->forCompany($companyId);
                $municipal = (new ItemMunicipalValidationResolver())->resolve(
                    itemId: $itemId,
                    companyId: $companyId,
                    nationalCode: (string) ($profile->codigo_tributacao_nacional ?? ''),
                    municipioIbge: $municipalContext['municipio_ibge'],
                    sandboxMode: $municipalContext['sandbox_mode'],
                );
                $validation = (new ItemFiscalValidationSummary())->combine($validation, $municipal);
            }

            $view->with('nfseLc116Catalog', $catalog);
            $view->with('nfseItemFiscalProfile', $profile);
            $view->with('nfseItemFiscalValidation', $validation);
        });
    }


}
