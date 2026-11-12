<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Interfaces\Utility\DocumentNumber as DocumentNumberInterface;
use App\Jobs\Common\CreateContact;
use App\Jobs\Document\CreateDocument;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document as Invoice;
use App\Models\Setting\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\AdnAccountingPreview;
use Modules\Nfse\Application\IbsCbsReceivedEvidence;
use Modules\Nfse\Models\AdnReviewEvent;
use Modules\Nfse\Models\AdnSyncDocument;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\NfseCompanyFiscalSettings;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlSignatureVerifier;

class AdnController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $this->requireCompanyId();

        // All fiscal data is loaded through the same JSON contracts available
        // to external clients; HTML only provides presentation and CSRF.
        return view('nfse::adn.index');
    }

    /** Company-scoped choices needed to review received services, not a second CRM CRUD. */
    public function reviewOptions(): JsonResponse
    {
        $companyId = $this->requireCompanyId();
        $operator = auth()->user();
        $issuerCnpj = null;

        if ($operator?->can('read-nfse-settings')) {
            try {
                $issuerCnpj = (string) (new NfseCompanyFiscalSettings())->value(
                    $companyId,
                    'nfse.cnpj_prestador',
                    '',
                );
            } catch (\DomainException) {
                // An unconfigured company does not leak another issuer.
            }
        }

        return $this->jsonResponse(['data' => [
            'issuer_cnpj' => $issuerCnpj,
            'can_create_vendor' => (bool) $operator?->can('create-purchases-vendors'),
            'can_import' => (bool) $operator?->can('create-purchases-bills'),
            'can_ignore' => (bool) $operator?->can('update-purchases-bills'),
            'can_query_distribution' => (bool) $operator?->can('read-nfse-settings'),
            'vendors' => Contact::query()
                ->vendor()->enabled()->where('company_id', $companyId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Contact $vendor): array => [
                    'id' => (int) $vendor->id,
                    'name' => (string) $vendor->name,
                ])->values()->all(),
            'categories' => Category::query()
                ->expense()->where('company_id', $companyId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ])->values()->all(),
            'items' => Item::query()
                ->enabled()->where('company_id', $companyId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Item $item): array => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                ])->values()->all(),
        ]]);
    }

    /**
     * List locally synchronized ADN records, never exposing raw XML in lists.
     * This is a read of persisted distribution, not another synchronization.
     */
    public function documents(Request $request): JsonResponse
    {
        $companyId = $this->requireCompanyId();
        $limit = $request->query('limit', 50);
        $status = $request->query('review_status');
        $role = $request->query('fiscal_role');
        $includePreview = $request->query('include_preview', '0');

        if (!in_array($includePreview, ['0', '1'], true)) {
            return $this->jsonResponse(['message' => 'Invalid include_preview.'], 422);
        }

        if (!is_scalar($limit) || preg_match('/^[1-9]\d*$/', (string) $limit) !== 1 || (int) $limit > 100) {
            return $this->jsonResponse(['message' => 'Invalid limit.'], 422);
        }

        if ($status !== null && (!is_string($status) || !in_array($status, ['pending', 'ignored', 'imported', 'not_applicable'], true))) {
            return $this->jsonResponse(['message' => 'Invalid review status.'], 422);
        }

        if ($role !== null && (!is_string($role) || !in_array($role, ['received', 'intermediated', 'emitted', 'event', 'unknown'], true))) {
            return $this->jsonResponse(['message' => 'Invalid fiscal role.'], 422);
        }

        $query = AdnSyncDocument::query()
            ->where('company_id', $companyId)
            ->when($status !== null, static fn ($q) => $q->where('review_status', $status))
            ->when($role !== null, static fn ($q) => $q->where('fiscal_role', $role))
            ->orderByDesc('id');

        $page = $query->paginate((int) $limit);
        $previewer = $includePreview === '1' ? new AdnAccountingPreview() : null;
        $ibsCbsReader = $previewer !== null ? new IbsCbsReceivedEvidence() : null;

        return $this->jsonResponse([
            'data' => collect($page->items())->map(static function (AdnSyncDocument $item) use ($previewer, $ibsCbsReader): array {
                $row = [
                    'id' => $item->id,
                    'document_key' => $item->document_key,
                    'nsu' => $item->nsu,
                    'chave_acesso' => $item->chave_acesso,
                    'tipo_documento' => $item->tipo_documento,
                    'tipo_evento' => $item->tipo_evento,
                    'data_hora_geracao' => $item->data_hora_geracao,
                    'fiscal_role' => $item->fiscal_role,
                    'review_status' => $item->review_status,
                    'imported_document_id' => $item->imported_document_id,
                    'ignored_at' => $item->ignored_at,
                    'recognized_event' => $item->recognized_event,
                ];

                if ($previewer !== null && in_array($item->fiscal_role, ['received', 'intermediated'], true)) {
                    $row['ibs_cbs_credit_candidate'] = null;
                    try {
                        $xml = (string) $item->xml;
                        // Only the proven received role can be a credit review candidate.
                        // No credit amount is booked or eligibility asserted here.
                        if ($item->fiscal_role === 'received' && $item->review_status !== 'ignored') {
                            $row['ibs_cbs_credit_candidate'] = $ibsCbsReader->fromAuthorizedXml($xml)
                                + ['environment' => (string) $item->environment];
                        }
                        $row['accounting_preview'] = $previewer->fromAuthorizedXml($xml);
                    } catch (\InvalidArgumentException) {
                        $row['accounting_preview'] = null;
                        $row['ibs_cbs_credit_candidate'] = null;
                    }
                }

                return $row;
            })->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Immutable decision trail, scoped exactly like the ADN record itself. */
    public function reviewHistory(int $document, Request $request): JsonResponse
    {
        $companyId = $this->requireCompanyId();
        AdnSyncDocument::query()->whereKey($document)
            ->where('company_id', $companyId)->firstOrFail();

        $limit = $request->query('limit', 50);
        if (!is_scalar($limit) || preg_match('/^[1-9]\d*$/', (string) $limit) !== 1 || (int) $limit > 100) {
            return $this->jsonResponse(['message' => 'Invalid limit.'], 422);
        }

        $page = AdnReviewEvent::query()
            ->where('company_id', $companyId)
            ->where('adn_document_id', $document)
            ->orderByDesc('id')
            ->paginate((int) $limit);

        return $this->jsonResponse([
            'data' => collect($page->items())->map(static fn (AdnReviewEvent $event): array => [
                'id' => (int) $event->id,
                'action' => (string) $event->action,
                'reason' => $event->reason,
                'bill_id' => $event->bill_id,
                'operator_id' => $event->operator_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Preview is deliberately a pure read; never creates a vendor or bill. */
    public function preview(int $document): JsonResponse
    {
        $companyId = $this->requireCompanyId();
        $row = AdnSyncDocument::query()
            ->whereKey($document)
            ->where('company_id', $companyId)
            ->whereIn('fiscal_role', ['received', 'intermediated'])
            ->firstOrFail();

        try {
            $xml = (string) $row->xml;
            $creditCandidate = $row->fiscal_role === 'received' && $row->review_status !== 'ignored'
                ? (new IbsCbsReceivedEvidence())->fromAuthorizedXml($xml)
                    + ['environment' => (string) $row->environment]
                : null;
            $preview = (new AdnAccountingPreview())->fromAuthorizedXml($xml);
        } catch (\InvalidArgumentException) {
            return $this->jsonResponse(['message' => trans('nfse::general.adn.review_invalid_xml')], 422);
        }

        return $this->jsonResponse([
            'data' => [
                'document_id' => (int) $row->id,
                'fiscal_role' => $row->fiscal_role,
                'review_status' => $row->review_status,
                'accounting_preview' => $preview,
                'ibs_cbs_credit_candidate' => $creditCandidate,
            ],
        ]);
    }

    public function ignore(int $document, ?Request $request = null): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $companyId = $this->requireCompanyId();
        $request ??= request();

        $status = DB::transaction(function () use ($document, $companyId): string {
            $reviewDocument = AdnSyncDocument::query()
                ->whereKey($document)
                ->where('company_id', $companyId)
                ->whereIn('fiscal_role', ['received', 'intermediated'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $reviewDocument->imported_document_id > 0) {
                $this->appendReviewEvent($companyId, $document, 'conflict', 'already_imported');

                return 'conflict';
            }

            if ($reviewDocument->review_status === 'ignored') {
                return 'ignored';
            }

            if ($reviewDocument->review_status !== 'pending') {
                $this->appendReviewEvent($companyId, $document, 'conflict', 'not_pending');

                return 'conflict';
            }

            $reviewDocument->review_status = 'ignored';
            $reviewDocument->ignored_at = now();
            $reviewDocument->save();
            $this->appendReviewEvent($companyId, $document, 'ignored');

            return 'ignored';
        });

        if ($status === 'conflict') {
            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_not_pending')], 409)
                : redirect()->route('nfse.adn.index')->with('error', trans('nfse::general.adn.review_not_pending'));
        }

        return $request->expectsJson()
            ? $this->jsonResponse(['data' => ['document_id' => $document, 'review_status' => 'ignored']])
            : redirect()->route('nfse.adn.index')
                ->with('success', trans('nfse::general.adn.review_ignored'));
    }

    public function importDraft(int $document, Request $request): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $companyId = $this->requireCompanyId();

        $reviewDocument = AdnSyncDocument::query()
            ->whereKey($document)
            ->whereIn('fiscal_role', ['received', 'intermediated'])
            ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
            ->firstOrFail();

        if ((int) ($reviewDocument->imported_document_id ?? 0) > 0) {
            $existingBill = Invoice::query()
                ->whereKey((int) $reviewDocument->imported_document_id)
                ->where('type', Invoice::BILL_TYPE)
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->first();

            if ($existingBill instanceof Invoice) {
                return $request->expectsJson()
                    ? $this->jsonResponse(['data' => [
                        'document_id' => $document,
                        'bill_id' => (int) $existingBill->id,
                        'redirect_url' => route('bills.show', $existingBill->id, false),
                        'review_status' => 'imported',
                        'already_imported' => true,
                    ]])
                    : redirect()->route('bills.show', $existingBill->id)
                        ->with('info', trans('nfse::general.adn.review_already_imported'));
            }
        }

        if ((string) $reviewDocument->review_status !== 'pending') {
            $this->appendReviewEvent($companyId, $document, 'rejected', 'not_pending');

            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_not_pending')], 409)
                : redirect()->route('nfse.adn.index')
                    ->with('error', trans('nfse::general.adn.review_not_pending'));
        }

        try {
            $preview = (new AdnAccountingPreview())->fromAuthorizedXml((string) $reviewDocument->xml);
        } catch (\InvalidArgumentException) {
            $this->appendReviewEvent($companyId, $document, 'rejected', 'invalid_xml');

            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_invalid_xml')], 422)
                : redirect()->route('nfse.adn.index')
                    ->with('error', trans('nfse::general.adn.review_invalid_xml'));
        }

        $category = Category::query()
            ->expense()
            ->whereKey((int) $request->input('category_id'))
            ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
            ->first();

        $item = Item::query()
            ->enabled()
            ->whereKey((int) $request->input('item_id'))
            ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
            ->first();

        if (!$category instanceof Category || !$item instanceof Item) {
            $this->appendReviewEvent($companyId, $document, 'rejected', 'invalid_mapping');
            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_mapping_required')], 422)
                : redirect()->route('nfse.adn.index')
                    ->with('error', trans('nfse::general.adn.review_mapping_required'));
        }

        $issuedAt = trim((string) $request->input('issued_at', ''));
        $dueAt = trim((string) $request->input('due_at', ''));

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt) !== 1
        ) {
            $this->appendReviewEvent($companyId, $document, 'rejected', 'invalid_dates');
            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_dates_required')], 422)
                : redirect()->route('nfse.adn.index')
                    ->with('error', trans('nfse::general.adn.review_dates_required'));
        }

        $grossValue = trim((string) ($preview['gross_value'] ?? ''));

        if ($grossValue === '' || !is_numeric($grossValue) || (float) $grossValue <= 0) {
            $this->appendReviewEvent($companyId, $document, 'rejected', 'invalid_amount');
            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.review_invalid_amount')], 422)
                : redirect()->route('nfse.adn.index')
                    ->with('error', trans('nfse::general.adn.review_invalid_amount'));
        }

        $alreadyImported = false;
        try {
            $bill = DB::transaction(function () use (
                &$alreadyImported,
                $reviewDocument,
                $document,
                $companyId,
                $request,
                $preview,
                $category,
                $item,
                $grossValue,
                $issuedAt,
                $dueAt
            ): Invoice {
                $locked = AdnSyncDocument::query()
                    ->whereKey($reviewDocument->id)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) ($locked->imported_document_id ?? 0) > 0) {
                    $alreadyImported = true;

                    return Invoice::query()
                        ->whereKey((int) $locked->imported_document_id)
                        ->where('company_id', $companyId)
                        ->where('type', Invoice::BILL_TYPE)
                        ->firstOrFail();
                }

                if ($locked->review_status !== 'pending') {
                    throw new \DomainException('review_not_pending');
                }

                $contact = $this->resolveReviewedVendor($request, $preview, $category, $companyId);
                if (!$contact instanceof Contact) {
                    throw new \DomainException('review_vendor_required');
                }

                $billRequest = new Request([
                    'company_id' => $companyId,
                    'type' => Invoice::BILL_TYPE,
                    'document_number' => app(DocumentNumberInterface::class)->getNextNumber(Invoice::BILL_TYPE, $contact),
                    'issued_at' => $issuedAt . ' 00:00:00',
                    'due_at' => $dueAt . ' 00:00:00',
                    'currency_code' => default_currency(),
                    'currency_rate' => '1',
                    'category_id' => $category->id,
                    'contact_id' => $contact->id,
                    'contact_name' => $contact->name,
                    'contact_email' => (string) ($contact->email ?? ''),
                    'contact_tax_number' => (string) ($contact->tax_number ?? ''),
                    'contact_phone' => (string) ($contact->phone ?? ''),
                    'contact_address' => (string) ($contact->address ?? ''),
                    'status' => 'draft',
                    'notes' => 'NFS-e ADN source: ' . ((string) ($reviewDocument->chave_acesso ?: $reviewDocument->document_key)),
                    'items' => [[
                        'item_id' => $item->id,
                        'name' => $item->name,
                        'description' => (string) ($preview['service_description'] ?? ''),
                        'category_id' => $category->id,
                        'tax_ids' => [],
                        'quantity' => '1',
                        'price' => $grossValue,
                        'currency' => default_currency(),
                    ]],
                ]);

                $created = $this->dispatch(new CreateDocument($billRequest));
                $locked->review_status = 'imported';
                $locked->imported_document_id = $created->id;
                $locked->save();
                $this->appendReviewEvent($companyId, $document, 'imported', null, (int) $created->id);

                return $created;
            });
        } catch (\DomainException $error) {
            if (!in_array($error->getMessage(), ['review_not_pending', 'review_vendor_required'], true)) {
                throw $error;
            }

            $reason = $error->getMessage();
            $this->appendReviewEvent($companyId, $document, 'rejected', $reason);

            return $request->expectsJson()
                ? $this->jsonResponse(['message' => trans('nfse::general.adn.' . $reason)], $reason === 'review_not_pending' ? 409 : 422)
                : redirect()->route('nfse.adn.index')->with('error', trans('nfse::general.adn.' . $reason));
        }

        return $request->expectsJson()
            ? $this->jsonResponse(['data' => [
                'document_id' => $document,
                'bill_id' => (int) $bill->id,
                'redirect_url' => route('bills.show', $bill->id, false),
                'review_status' => 'imported',
                'already_imported' => $alreadyImported,
            ]], $alreadyImported ? 200 : 201)
            : redirect()->route('bills.show', $bill->id)
                ->with('success', trans('nfse::general.adn.review_imported'));
    }

    /** Append only, with no fiscal XML or external credentials. */
    private function appendReviewEvent(
        int $companyId,
        int $documentId,
        string $action,
        ?string $reason = null,
        ?int $billId = null,
    ): void {
        $operatorId = auth()->id();
        AdnReviewEvent::query()->create([
            'company_id' => $companyId,
            'adn_document_id' => $documentId,
            'operator_id' => $operatorId === null ? null : (int) $operatorId,
            'action' => $action,
            'reason' => $reason,
            'bill_id' => $billId,
        ]);
    }

    /**
     * @param array<string,string> $preview
     */
    protected function resolveReviewedVendor(
        Request $request,
        array $preview,
        Category $category,
        int $companyId,
    ): ?Contact {
        $contactId = (int) $request->input('contact_id', 0);

        if ($contactId > 0) {
            return Contact::query()
                ->vendor()
                ->enabled()
                ->whereKey($contactId)
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->first();
        }

        if (!$request->boolean('create_vendor')) {
            return null;
        }

        // Importing a bill must not grant the independent right to create vendors.
        abort_unless(auth()->user()?->can('create-purchases-vendors'), 403);

        $name = trim((string) ($preview['supplier_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $taxNumber = trim((string) ($preview['supplier_tax_number'] ?? ''));

        if ($taxNumber !== '') {
            $existing = Contact::query()
                ->vendor()
                ->where('tax_number', $taxNumber)
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->first();

            if ($existing instanceof Contact) {
                return $existing;
            }
        }

        return $this->dispatch(new CreateContact(new Request([
            'company_id' => $companyId,
            'type' => Contact::VENDOR_TYPE,
            'name' => $name,
            'tax_number' => $taxNumber,
            'category_id' => $category->id,
            'currency_code' => default_currency(),
            'enabled' => 1,
        ])));
    }

    /**
     * @return list<array{document:object,preview:?array<string,string>}>
     */
    protected function accountingReviewQueue(): array
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0) {
            return [];
        }

        $sql = 'SELECT id, company_id, document_key, chave_acesso, fiscal_role, xml'
            . ' FROM nfse_adn_documents'
            . " WHERE review_status = 'pending'"
            . " AND fiscal_role IN ('received', 'intermediated')";
        $bindings = [];

        if ($companyId > 0) {
            $sql .= ' AND company_id = ?';
            $bindings[] = $companyId;
        }

        $sql .= ' ORDER BY id DESC LIMIT 50';
        $previewer = new AdnAccountingPreview();
        $rows = [];

        foreach (DB::select($sql, $bindings) as $document) {
            try {
                $preview = $previewer->fromAuthorizedXml((string) ($document->xml ?? ''));
            } catch (\InvalidArgumentException) {
                $preview = null;
            }

            $rows[] = [
                'document' => $document,
                'preview' => $preview,
            ];
        }

        return $rows;
    }

    public function distribution(Request $request): JsonResponse
    {
        $rawNsu = $request->isMethod('post')
            ? $request->input('nsu', 0)
            : $request->query('nsu', 0);
        if (!is_numeric($rawNsu) || (int) $rawNsu < 0) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_nsu'),
            ], 422);
        }

        $cnpjInput = $request->isMethod('post')
            ? $request->input('cnpj')
            : $request->query('cnpj');
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $cnpjInput) ?? '');

        if ($cnpj !== '' && preg_match('/^[A-Z0-9]{12}\\d{2}$/', $cnpj) !== 1) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_cnpj'),
            ], 422);
        }

        // The optional ADN filter must never bypass the company-owned
        // certificate requirement. Without an active profile, fail before
        // constructing a client or making a remote request.
        try {
            $issuerCnpj = $this->companyCnpjForAdn();
        } catch (\DomainException) {
            return $this->jsonResponse(['reason' => 'company_profile_unavailable'], 409);
        }

        if ($cnpjInput === null) {
            $cnpj = $issuerCnpj;
        }

        $loteRaw = strtolower(trim((string) ($request->isMethod('post')
            ? $request->input('lote', '1')
            : $request->query('lote', '1'))));
        $lote = in_array($loteRaw, ['1', 'true', 'on', 'yes'], true);

        try {
            $result = $this->fetchAdnDistribution((int) $rawNsu, $cnpj !== '' ? $cnpj : null, $lote);
            if ($request->isMethod('post')) {
                // The operational API reports the remote cursor and review
                // metadata, never XML bodies. Authenticated browser GET keeps
                // the full document detail required by its review UI.
                $result['documents'] = array_map(static function (mixed $row): array {
                    if (!is_array($row)) {
                        return [];
                    }

                    unset($row['xml']);

                    return $row;
                }, is_array($result['documents'] ?? null) ? $result['documents'] : []);
            }

            return $this->jsonResponse(['data' => $result]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.distribution_query_failed'),
            ], 502);
        }
    }

    /** Company-less admin contexts must not widen fiscal queries or mutations. */
    private function requireCompanyId(): int
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0) {
            abort(404);
        }

        return $companyId;
    }

    protected function companyCnpjForAdn(): string
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        return (string) (new NfseCompanyFiscalSettings())->value($companyId, 'nfse.cnpj_prestador', '');
    }

    public function events(Invoice $invoice): JsonResponse
    {
        if ((int) $invoice->company_id !== (int) company_id()) {
            abort(404);
        }

        $accessKey = $this->receiptAccessKey($invoice);

        if ($accessKey === '') {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.events_missing_receipt'),
            ], 404);
        }

        try {
            return $this->jsonResponse([
                'data' => $this->fetchAdnEvents($accessKey),
            ]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.events_query_failed'),
            ], 502);
        }
    }

    protected function receiptAccessKey(Invoice $invoice): string
    {
        $receipt = NfseReceipt::query()
            ->where('invoice_id', (int) $invoice->id)
            ->latest('id')
            ->first();

        return trim((string) ($receipt?->chave_acesso ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAdnEvents(string $accessKey): array
    {
        return $this->withAdnClient(
            fn (AdnClient $client): array => $this->normalizeDistribution($client->listEvents($accessKey)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAdnDistribution(int $nsu, ?string $cnpj, bool $lote): array
    {
        return $this->withAdnClient(
            fn (AdnClient $client): array => $this->reconcileDistribution(
                $this->normalizeDistribution($client->getDfe($nsu, $cnpj, $lote)),
            ),
        );
    }

    /**
     * @param array<string, mixed> $distribution
     * @return array<string, mixed>
     */
    protected function reconcileDistribution(array $distribution): array
    {
        $documents = is_array($distribution['documents'] ?? null)
            ? $distribution['documents']
            : [];

        $accessKeys = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $accessKey = trim((string) ($document['chave_acesso'] ?? ''));
            if ($accessKey !== '') {
                $accessKeys[$accessKey] = true;
            }
        }

        $matches = $this->localReceiptMatches(array_keys($accessKeys));
        $matched = 0;

        foreach ($documents as $index => $document) {
            if (!is_array($document)) {
                continue;
            }

            $accessKey = trim((string) ($document['chave_acesso'] ?? ''));
            $localReceipt = $accessKey !== '' ? ($matches[$accessKey] ?? null) : null;

            if ($localReceipt !== null) {
                $matched++;
            }

            $documents[$index]['local_receipt'] = $localReceipt;
        }

        $distribution['documents'] = $documents;
        $distribution['reconciliation'] = [
            'matched' => $matched,
            'unmatched' => max(0, count($documents) - $matched),
        ];

        return $distribution;
    }

    /**
     * @param list<string> $accessKeys
     * @return array<string, array{invoice_id:int, nfse_number:string, status:string}>
     */
    protected function localReceiptMatches(array $accessKeys): array
    {
        if ($accessKeys === []) {
            return [];
        }

        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        if ($companyId <= 0) {
            return [];
        }

        $rows = NfseReceipt::query()
            ->whereIn('chave_acesso', $accessKeys)
            ->when($companyId > 0, static function ($query) use ($companyId): void {
                $query->whereHas('invoice', static function ($invoiceQuery) use ($companyId): void {
                    $invoiceQuery->where('company_id', $companyId);
                });
            })
            ->get(['invoice_id', 'chave_acesso', 'nfse_number', 'status']);

        $matches = [];

        foreach ($rows as $receipt) {
            $accessKey = trim((string) ($receipt->chave_acesso ?? ''));
            if ($accessKey === '') {
                continue;
            }

            $matches[$accessKey] = [
                'invoice_id' => (int) ($receipt->invoice_id ?? 0),
                'nfse_number' => (string) ($receipt->nfse_number ?? ''),
                'status' => (string) ($receipt->status ?? ''),
            ];
        }

        return $matches;
    }

    /**
     * @template T
     * @param \Closure(AdnClient): T $operation
     * @return T
     */
    protected function withAdnClient(\Closure $operation): mixed
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0) {
            throw new \DomainException('ADN query requires an authorized company context.');
        }

        $context = $this->makeFiscalClientFactory()->adn($this->sandboxModeEnabled(), $companyId);

        try {
            return $operation($context->adnClient());
        } finally {
            $context->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeDistribution(AdnDistributionData $result): array
    {
        return [
            'status_processamento' => $result->statusProcessamento,
            'ambiente' => $result->ambiente,
            'versao_aplicativo' => $result->versaoAplicativo,
            'data_hora_processamento' => $result->dataHoraProcessamento,
            'ultimo_nsu' => $result->ultimoNsu,
            'documents' => array_map(
                fn ($document): array => [
                    'nsu' => $document->nsu,
                    'chave_acesso' => $document->chaveAcesso,
                    'tipo_documento' => $document->tipoDocumento,
                    'tipo_evento' => $document->tipoEvento,
                    'data_hora_geracao' => $document->dataHoraGeracao,
                    'signature_integrity' => $this->xmlSignatureIntegrity($document->xml),
                    'xml' => $document->xml,
                ],
                $result->documents,
            ),
            'alerts' => array_map(
                static fn ($message): array => [
                    'code' => $message->code,
                    'description' => $message->description,
                    'complement' => $message->complement,
                    'parameters' => $message->parameters,
                ],
                $result->alerts,
            ),
            'errors' => array_map(
                static fn ($message): array => [
                    'code' => $message->code,
                    'description' => $message->description,
                    'complement' => $message->complement,
                    'parameters' => $message->parameters,
                ],
                $result->errors,
            ),
        ];
    }

    protected function xmlSignatureIntegrity(?string $xml): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        if (preg_match('/<(?:[A-Za-z0-9_.-]+:)?Signature\\b/', $xml) !== 1) {
            return 'not_present';
        }

        return (new XmlSignatureVerifier())->verify($xml)
            ? 'valid'
            : 'invalid';
    }

    protected function makeFiscalClientFactory(): FiscalClientFactory
    {
        return app(FiscalClientFactory::class);
    }

    protected function sandboxModeEnabled(): bool
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $value = (new \Modules\Nfse\Support\NfseCompanyFiscalSettings())->value($companyId, 'nfse.sandbox_mode', true);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }
}
