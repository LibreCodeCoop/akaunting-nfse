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
use Modules\Nfse\Models\AdnSyncDocument;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlSignatureVerifier;

class AdnController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        return view('nfse::adn.index', [
            'reviewDocuments' => $this->accountingReviewQueue(),
            'reviewVendors' => Contact::query()
                ->vendor()
                ->enabled()
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->orderBy('name')
                ->get(['id', 'name', 'tax_number']),
            'reviewCategories' => Category::query()
                ->expense()
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'reviewItems' => Item::query()
                ->enabled()
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function ignore(int $document): \Illuminate\Http\RedirectResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        $query = AdnSyncDocument::query()
            ->whereKey($document)
            ->whereIn('fiscal_role', ['received', 'intermediated']);

        if ($companyId > 0) {
            $query->where('company_id', $companyId);
        }

        $reviewDocument = $query->firstOrFail();
        $reviewDocument->review_status = 'ignored';
        $reviewDocument->ignored_at = now();
        $reviewDocument->save();

        return redirect()->route('nfse.adn.index')
            ->with('success', trans('nfse::general.adn.review_ignored'));
    }

    public function importDraft(int $document, Request $request): \Illuminate\Http\RedirectResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

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
                return redirect()->route('bills.show', $existingBill->id)
                    ->with('info', trans('nfse::general.adn.review_already_imported'));
            }
        }

        if ((string) $reviewDocument->review_status !== 'pending') {
            return redirect()->route('nfse.adn.index')
                ->with('error', trans('nfse::general.adn.review_not_pending'));
        }

        try {
            $preview = (new AdnAccountingPreview())->fromAuthorizedXml((string) $reviewDocument->xml);
        } catch (\InvalidArgumentException) {
            return redirect()->route('nfse.adn.index')
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
            return redirect()->route('nfse.adn.index')
                ->with('error', trans('nfse::general.adn.review_mapping_required'));
        }

        $issuedAt = trim((string) $request->input('issued_at', ''));
        $dueAt = trim((string) $request->input('due_at', ''));

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt) !== 1
        ) {
            return redirect()->route('nfse.adn.index')
                ->with('error', trans('nfse::general.adn.review_dates_required'));
        }

        $contact = $this->resolveReviewedVendor(
            $request,
            $preview,
            $category,
            $companyId,
        );

        if (!$contact instanceof Contact) {
            return redirect()->route('nfse.adn.index')
                ->with('error', trans('nfse::general.adn.review_vendor_required'));
        }

        $grossValue = trim((string) ($preview['gross_value'] ?? ''));

        if ($grossValue === '' || !is_numeric($grossValue) || (float) $grossValue <= 0) {
            return redirect()->route('nfse.adn.index')
                ->with('error', trans('nfse::general.adn.review_invalid_amount'));
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

        $bill = DB::transaction(function () use ($reviewDocument, $billRequest, $companyId): Invoice {
            $locked = AdnSyncDocument::query()
                ->whereKey($reviewDocument->id)
                ->when($companyId > 0, static fn ($query) => $query->where('company_id', $companyId))
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) ($locked->imported_document_id ?? 0) > 0) {
                return Invoice::query()
                    ->whereKey((int) $locked->imported_document_id)
                    ->where('type', Invoice::BILL_TYPE)
                    ->firstOrFail();
            }

            $created = $this->dispatch(new CreateDocument($billRequest));
            $locked->review_status = 'imported';
            $locked->imported_document_id = $created->id;
            $locked->save();

            return $created;
        });

        return redirect()->route('bills.show', $bill->id)
            ->with('success', trans('nfse::general.adn.review_imported'));
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
        $rawNsu = $request->query('nsu', 0);
        if (!is_numeric($rawNsu) || (int) $rawNsu < 0) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_nsu'),
            ], 422);
        }

        $cnpj = strtoupper(preg_replace(
            '/[^A-Z0-9]/i',
            '',
            (string) $request->query('cnpj', setting('nfse.cnpj_prestador', '')),
        ) ?? '');

        if ($cnpj !== '' && preg_match('/^[A-Z0-9]{12}\\d{2}$/', $cnpj) !== 1) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_cnpj'),
            ], 422);
        }

        $loteRaw = strtolower(trim((string) $request->query('lote', '1')));
        $lote = in_array($loteRaw, ['1', 'true', 'on', 'yes'], true);

        try {
            return $this->jsonResponse([
                'data' => $this->fetchAdnDistribution((int) $rawNsu, $cnpj !== '' ? $cnpj : null, $lote),
            ]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.distribution_query_failed'),
            ], 502);
        }
    }

    public function events(Invoice $invoice): JsonResponse
    {
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
        $context = $this->makeFiscalClientFactory()->adn($this->sandboxModeEnabled());

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
        $value = setting('nfse.sandbox_mode', true);

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
