<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Events\Document\DocumentMarkedSent;
use App\Models\Common\Contact;
use App\Models\Common\Item as CommonItem;
use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\ArtifactPathBuilder;
use Modules\Nfse\Application\CancelInvoiceNfse;
use Modules\Nfse\Application\EmissionAttemptJournal;
use Modules\Nfse\Application\FederalTaxReadiness;
use Modules\Nfse\Application\FiscalGroupReceiptState;
use Modules\Nfse\Application\FiscalProfileEmissionReadiness;
use Modules\Nfse\Application\IbsCbsEmissionReadiness;
use Modules\Nfse\Application\IbsCbsPayloadResolver;
use Modules\Nfse\Application\InvoiceDpsBuilder;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Application\InvoiceFiscalGroupBuilder;
use Modules\Nfse\Application\InvoiceFiscalProfileSelector;
use Modules\Nfse\Application\IssqnPayloadResolver;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Application\IssueInvoiceNfse;
use Modules\Nfse\Application\OfficialIssuanceRejection;
use Modules\Nfse\Application\PostEmissionDispatcher;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Application\ReceiptNumberResolver;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Application\RecoverInvoiceEmission;
use Modules\Nfse\Application\RefreshInvoiceNfse;
use Modules\Nfse\Application\RuntimeDpsFactory;
use Modules\Nfse\Application\SubstituteInvoiceNfse;
use Modules\Nfse\Application\SubstitutionDpsBuilder;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Support\InvoiceFederalPayloadResolver;
use Modules\Nfse\Support\InvoiceTakerResolver;
use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Support\OperationalReadinessResolver;
use Modules\Nfse\Support\TakerAddressReadiness;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseGenerator;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\GatewayException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder;

class InvoiceController extends Controller
{
    private const INVOICE_NOTES_SETTING_KEY = 'invoice.notes';

    private ?FiscalClientContext $clientContext = null;

    protected string $indexSortBy = 'due_at';

    protected string $indexSortDirection = 'desc';

    public function dashboard(): \Illuminate\View\View
    {
        $stats = $this->dashboardStats();
        $recentReceipts = $this->dashboardRecentReceipts();

        return view('nfse::dashboard.index', compact('stats', 'recentReceipts'));
    }

    /**
     * @return list<object>
     */
    protected function dashboardRecentReceipts(): array
    {
        return DB::select(
            'SELECT receipt.id, receipt.invoice_id, receipt.nfse_number, receipt.status,'
            . ' receipt.data_emissao, document.document_number, contact.name AS customer_name'
            . ' FROM nfse_receipts AS receipt'
            . ' INNER JOIN documents AS document ON document.id = receipt.invoice_id'
            . ' LEFT JOIN contacts AS contact ON contact.id = document.contact_id'
            . ' WHERE document.company_id = ? AND document.type = ?'
            . ' ORDER BY receipt.id DESC LIMIT 10',
            [(int) company_id(), Invoice::INVOICE_TYPE],
        );
    }

    public function fiscalLedger(?Request $request = null): \Illuminate\View\View
    {
        $request = $this->currentRequest($request);
        $status = (string) ($request?->query('status', 'all') ?? 'all');
        if (!in_array($status, ['all', 'emitted', 'cancelled', 'processing', 'substituted'], true)) {
            $status = 'all';
        }

        $search = $this->normalizedIndexSearch($request?->query('search'));
        $from = $request?->query('from');
        $to = $request?->query('to');
        $from = is_string($from) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from) && checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4)) ? $from : null;
        $to = is_string($to) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to) && checkdate((int) substr($to, 5, 2), (int) substr($to, 8, 2), (int) substr($to, 0, 4)) ? $to : null;
        $query = NfseReceipt::query()
            ->with('invoice.contact')
            ->whereHas('invoice', static fn ($query) => $query
                ->where('company_id', (int) company_id())
                ->where('type', Invoice::INVOICE_TYPE));

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== null) {
            $query->where(static fn ($query) => $query
                ->where('nfse_number', 'like', '%' . $search . '%')
                ->orWhere('chave_acesso', 'like', '%' . $search . '%')
                ->orWhereHas('invoice.contact', static fn ($contact) => $contact
                    ->where('name', 'like', '%' . $search . '%')));
        }

        if ($from !== null) {
            $query->whereDate('data_emissao', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('data_emissao', '<=', $to);
        }

        $receipts = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('nfse::ledger.index', compact('receipts', 'status', 'search', 'from', 'to'));
    }

    public function index(?Request $request = null): \Illuminate\View\View|RedirectResponse
    {
        $request = $this->currentRequest($request);

        $hasExplicitState = $this->requestHasIndexState($request);
        $savedPreferences = $this->loadIndexPreferences();

        if (!$hasExplicitState && $savedPreferences !== [] && $this->canRestoreIndexPreferences($savedPreferences)) {
            return redirect()->route('nfse.invoices.index', $this->indexRestoreQueryParams($savedPreferences));
        }

        $search = $this->normalizedIndexSearch($request?->query('search', $request?->query('q')));
        $requestedStatus = $request?->query('status');
        $requestedPerPage = $request?->query('limit', $request?->query('per_page'));
        $requestedSortBy = $request?->query('sort', $request?->query('sort_by'));
        $requestedSortDirection = $request?->query('direction', $request?->query('sort_direction'));

        if (!$hasExplicitState && $savedPreferences !== [] && $this->canRestoreIndexPreferences($savedPreferences)) {
            $search ??= $savedPreferences['search'];
            $requestedStatus ??= $savedPreferences['status'];
            $requestedPerPage ??= $savedPreferences['per_page'];
            $requestedSortBy ??= $savedPreferences['sort_by'];
            $requestedSortDirection ??= $savedPreferences['sort_direction'];
        }

        $parsedFilters = $this->parsedIndexSearchFilters($search);
        $status = $requestedStatus !== null
            ? $this->normalizedIndexStatus($requestedStatus)
            : ($parsedFilters['status'] ?? 'all');
        $perPage = $requestedPerPage !== null
            ? $this->normalizedIndexPerPage($requestedPerPage)
            : ($parsedFilters['per_page'] ?? 25);
        $this->indexSortBy = $this->normalizedIndexSortBy($requestedSortBy);
        $this->indexSortDirection = $this->normalizedIndexSortDirection($requestedSortDirection);
        $searchTerm = $parsedFilters['search'];
        $searchStringCookieFilters = $this->searchStringCookieFilters($parsedFilters);
        $selectedStatuses = $this->selectedIndexStatuses($status);
        $includesPendingStatus = in_array('pending', $selectedStatuses, true);
        $receiptStatus = $this->receiptStatusForIndex($status);
        $overviewCounts = $this->listingOverviewCounts();
        $receipts = $receiptStatus !== null
            ? $this->receiptsForIndex($receiptStatus, $perPage, $searchTerm, $parsedFilters['date_emissao'] ?? null)
            : null;
        $pendingInvoices = $includesPendingStatus ? $this->pendingInvoices($perPage, $searchTerm) : null;
        if ($includesPendingStatus && $pendingInvoices !== null) {
            $pendingInvoices = $this->annotatePendingInvoicesFederalReadiness($pendingInvoices);
        }
        $pendingReadiness = $includesPendingStatus ? $this->emissionReadiness() : ['isReady' => true, 'checklist' => []];
        $sortBy = $this->indexSortBy;
        $sortDirection = $this->indexSortDirection;

        $this->saveIndexPreferences([
            'status' => $status,
            'per_page' => $perPage,
            'search' => $search,
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
        ]);

        return view('nfse::invoices.index', compact('receipts', 'pendingInvoices', 'pendingReadiness', 'overviewCounts', 'status', 'perPage', 'search', 'searchStringCookieFilters', 'sortBy', 'sortDirection'));
    }

    public function pending(?Request $request = null): RedirectResponse
    {
        $request = $this->currentRequest($request);
        $perPage = $request?->query('limit', $request?->query('per_page'));
        $search = $request?->query('search', $request?->query('q'));

        return redirect()->route('nfse.invoices.index', array_filter([
            'status' => 'pending',
            'limit' => $this->normalizedIndexPerPage($perPage),
            'search' => $this->normalizedIndexSearch($search),
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    public function show(Invoice $invoice): \Illuminate\View\View
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $receiptStatusLabel = $this->translateReceiptStatus((string) ($receipt->status ?? ''));
        $suggestedDiscriminacao = $this->buildDiscriminacao($invoice);
        $emailDefaults = $this->servicePreviewEmailDefaults($invoice);
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        return view('nfse::invoices.show', compact('invoice', 'receipt', 'receiptStatusLabel', 'suggestedDiscriminacao', 'emailDefaults', 'artifacts'));
    }

    public function postEmissionStatus(Invoice $invoice): JsonResponse
    {
        $receipt = NfseReceipt::query()
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        if (!$receipt instanceof NfseReceipt) {
            return response()->json([
                'data' => [
                    'status' => 'idle',
                    'poll' => false,
                    'receipt_id' => null,
                    'nfse_number' => null,
                    'stages' => [
                        'artifacts' => null,
                        'email' => null,
                    ],
                    'artifacts' => [
                        'xml' => ['ready' => false, 'download_url' => null],
                        'danfse' => ['ready' => false, 'download_url' => null],
                    ],
                    'error' => null,
                ],
            ]);
        }

        $snapshot = (new PostEmissionState())->snapshot($receipt);
        $authorizedXml = $this->authorizedXmlForReceipt($receipt);
        $xmlReady = $authorizedXml !== '' || trim((string) ($receipt->xml_webdav_path ?? '')) !== '';
        $danfseReady = $authorizedXml !== '' || trim((string) ($receipt->danfse_webdav_path ?? '')) !== '';

        return response()->json([
            'data' => [
                'status' => $snapshot['overall_status'],
                'poll' => $snapshot['poll'],
                'receipt_id' => (int) $receipt->id,
                'nfse_number' => (string) ($receipt->nfse_number ?? ''),
                'stages' => [
                    'artifacts' => $snapshot['artifacts_status'],
                    'email' => $snapshot['email_status'],
                ],
                'artifacts' => [
                    'xml' => [
                        'ready' => $xmlReady,
                        'download_url' => $xmlReady
                            ? route('nfse.invoices.artifacts.download', [
                                'company_id' => $invoice->company_id,
                                'invoice' => $invoice->id,
                                'artifact' => 'xml',
                            ])
                            : null,
                    ],
                    'danfse' => [
                        'ready' => $danfseReady,
                        'download_url' => $danfseReady
                            ? route('nfse.invoices.artifacts.download', [
                                'company_id' => $invoice->company_id,
                                'invoice' => $invoice->id,
                                'artifact' => 'danfse',
                            ])
                            : null,
                    ],
                ],
                'error' => $snapshot['error'],
            ],
        ]);
    }

    public function showEmitSuccess(Invoice $invoice): \Illuminate\View\View
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $receiptStatusLabel = $this->translateReceiptStatus((string) ($receipt->status ?? ''));
        $postEmissionStatus = (new PostEmissionState())->snapshot($receipt);
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        return view('nfse::invoices.partials.emit-success', compact('invoice', 'receipt', 'receiptStatusLabel', 'artifacts', 'postEmissionStatus'));
    }

    protected function translateReceiptStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        $key = match ($normalized) {
            'emitted' => 'nfse::general.invoices.status_emitted',
            'cancelled' => 'nfse::general.invoices.status_cancelled',
            'processing' => 'nfse::general.invoices.status_processing',
            'pending' => 'nfse::general.invoices.status_pending',
            default => null,
        };

        if ($key === null) {
            return $status;
        }

        return (string) trans($key);
    }

    public function downloadArtifact(Invoice $invoice, string $artifact): Response|RedirectResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();

        return $this->downloadReceiptArtifact($invoice, $receipt, $artifact);
    }

    public function downloadLedgerArtifact(int $receipt, string $artifact): Response|RedirectResponse
    {
        $fiscalReceipt = NfseReceipt::query()->where('id', $receipt)
            ->whereHas('invoice', static fn ($query) => $query
                ->where('company_id', (int) company_id())
                ->where('type', Invoice::INVOICE_TYPE))
            ->firstOrFail();
        $invoice = Invoice::findOrFail($fiscalReceipt->invoice_id);
        $this->ensureInvoiceRelationsLoaded($invoice);

        return $this->downloadReceiptArtifact($invoice, $fiscalReceipt, $artifact);
    }

    protected function downloadReceiptArtifact(Invoice $invoice, NfseReceipt $receipt, string $artifact): Response|RedirectResponse
    {

        if (!in_array($artifact, ['xml', 'danfse'], true)) {
            return redirect()->route('invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_invalid_type'));
        }

        $authorizedXml = $this->authorizedXmlForReceipt($receipt);
        $content = '';

        if ($authorizedXml !== '') {
            try {
                $content = $artifact === 'xml'
                    ? $authorizedXml
                    : (new DanfseGenerator())->generateFromXml($authorizedXml);
            } catch (\Throwable $throwable) {
                $this->safeLogError('NFS-e local artifact generation failed', [
                    'invoice_id' => $invoice->id,
                    'receipt_id' => $receipt->id,
                    'artifact' => $artifact,
                    'message' => $throwable->getMessage(),
                ]);
            }

            if ($content !== '' && ($artifact !== 'danfse' || str_starts_with($content, '%PDF-'))) {
                return $this->artifactDownloadResponse($receipt, $artifact, $content);
            }
        }

        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);
        $artifactData = $artifacts[$artifact] ?? null;

        if (!is_array($artifactData)) {
            return redirect()->route('invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_not_found'));
        }

        $path = is_string($artifactData['path'] ?? null)
            ? trim((string) $artifactData['path'])
            : '';

        if ($path === '' || !($artifactData['exists'] ?? false)) {
            return redirect()->route('invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_not_found'));
        }

        try {
            $content = $this->makeWebDavClientFromSettings()->get($path);
        } catch (\Throwable) {
            return redirect()->route('invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_not_found'));
        }

        return $this->artifactDownloadResponse($receipt, $artifact, $content);
    }

    protected function authorizedXmlForReceipt(NfseReceipt $receipt): string
    {
        try {
            return trim((string) $receipt->payload()->value('authorized_xml'));
        } catch (\Throwable) {
            return '';
        }
    }

    protected function artifactDownloadResponse(NfseReceipt $receipt, string $artifact, string $content): Response
    {
        $mimeType = $artifact === 'xml' ? 'application/xml' : 'application/pdf';
        $extension = $artifact === 'xml' ? 'xml' : 'pdf';
        $resolvedNfseNumber = trim((string) ($receipt->nfse_number ?? ''));
        $suffix = $resolvedNfseNumber !== ''
            ? '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $resolvedNfseNumber)
            : '';
        $fileName = 'nfse' . $suffix . '.' . $extension;

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function servicePreview(Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        $fiscalProfileReadiness = (new FiscalProfileEmissionReadiness())->evaluate($itemFiscalProfile);
        $fiscalGroups = $this->annotateFiscalGroupsWithReceiptState(
            $invoice,
            $this->invoiceFiscalGroups($invoice),
        );

        return response()->json([
            'missing_items'    => [],
            'available_services' => $this->availableInvoiceServices($invoice),
            'default_service_id' => 0,
            'requires_split'   => count($fiscalGroups) > 1,
            'fiscal_groups'    => $fiscalGroups,
            'fiscal_profile_validation' => $fiscalProfileReadiness,
            'suggested_description' => $this->buildDiscriminacao($invoice, $itemFiscalProfile['line_items'] ?? []),
            'email_defaults'   => $this->servicePreviewEmailDefaults($invoice),
            'taker_defaults'   => $this->servicePreviewTakerDefaults($invoice),
        ]);
    }

    public function emit(Invoice $invoice, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $request = $this->currentRequest($request);
        $this->ensureInvoiceRelationsLoaded($invoice);

        try {
            $substitutionReceipt = $this->substitutionReceiptFromRequest($invoice, $request);
        } catch (\InvalidArgumentException $e) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', trans('nfse::general.invoices.substitution_invalid', ['reason' => $e->getMessage()])),
            );
        }

        if (!$this->invoiceHasLineItems($invoice)) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_no_items')));
        }

        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);
        $this->persistDefaultDescriptionFromRequest($request);

        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        $fiscalGroups = $this->invoiceFiscalGroups($invoice);
        $selectedFiscalGroup = null;

        if (count($fiscalGroups) > 1) {
            $selectedGroupKey = trim((string) ($request?->input('nfse_fiscal_group_key', '') ?? ''));

            foreach ($fiscalGroups as $group) {
                if (hash_equals((string) ($group['key'] ?? ''), $selectedGroupKey)) {
                    $selectedFiscalGroup = $group;
                    break;
                }
            }

            if (!is_array($selectedFiscalGroup)) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.invoices.fiscal_group_selection_required')),
                );
            }
        }

        $emissionProfile = is_array($selectedFiscalGroup) ? $selectedFiscalGroup : $itemFiscalProfile;
        $rtcCategory = trim((string) ($emissionProfile['rtc_supply_category'] ?? ''));
        if ($rtcCategory !== '' && $rtcCategory !== 'ordinary_lc116') {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', $this->rtcUnsupportedCategoryMessage($rtcCategory)),
            );
        }

        $fiscalProfileReadiness = (new FiscalProfileEmissionReadiness())->evaluate($emissionProfile);
        if (($fiscalProfileReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', $this->invalidFiscalProfileMessage($fiscalProfileReadiness, $invoice)),
            );
        }

        $selectedDocumentItemIds = is_array($selectedFiscalGroup)
            ? array_values(array_filter(array_map(
                static fn (array $item): int => is_numeric($item['document_item_id'] ?? null)
                    ? (int) $item['document_item_id']
                    : 0,
                is_array($selectedFiscalGroup['items'] ?? null) ? $selectedFiscalGroup['items'] : [],
            ), static fn (int $id): bool => $id > 0))
            : null;
        $selectedFiscalAmount = is_array($selectedFiscalGroup)
            ? (float) ($selectedFiscalGroup['amount'] ?? 0)
            : null;
        $serviceAmount = $this->invoiceServiceAmount(
            $invoice,
            $selectedDocumentItemIds,
            $selectedFiscalAmount,
        );

        $ibsCbsServiceCode = is_array($selectedFiscalGroup)
            ? (string) ($selectedFiscalGroup['item_lista_servico'] ?? '')
            : (string) ($itemFiscalProfile['item_lista_servico'] ?? '');
        $ibsCbsReadiness = (new IbsCbsEmissionReadiness())->evaluate(
            competenceDate: (string) ($this->competenceDate($invoice) ?? ''),
            opcaoSimplesNacional: $this->normalizedOpcaoSimplesNacional(),
            itemListaServico: $ibsCbsServiceCode,
            settings: is_array(setting('nfse', [])) ? setting('nfse', []) : [],
            rtcSupplyCategory: $rtcCategory,
        );

        if (($ibsCbsReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', $this->ibsCbsBlockedMessage($ibsCbsReadiness)),
            );
        }

        $federalTaxReadiness = $this->federalTaxReadinessForInvoice(
            $invoice,
            $selectedDocumentItemIds,
            $serviceAmount,
        );

        if (($federalTaxReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', $this->emitBlockedFederalTaxMessage($federalTaxReadiness['missing'] ?? [])));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready')));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = $this->sandboxModeEnabled();
        $tomadorDocument = $this->resolvedTomadorDocument($invoice);
        $tomadorPayload = $this->tomadorPayload($invoice->contact, $invoice);

        try {
            $foreignTomador = $this->foreignTomadorPayloadFromRequest($request, $invoice, $tomadorPayload);
        } catch (\InvalidArgumentException $e) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', trans('nfse::general.invoices.emit_foreign_taker_invalid', ['reason' => $e->getMessage()])),
            );
        }

        if ($foreignTomador['enabled']) {
            $tomadorDocument = '';
            $tomadorPayload['codigo_municipio'] = '';
            $tomadorPayload['cep'] = '';
            $tomadorPayload['logradouro'] = $foreignTomador['logradouro'];
            $tomadorPayload['numero'] = $foreignTomador['numero'];
            $tomadorPayload['complemento'] = $foreignTomador['complemento'];
            $tomadorPayload['bairro'] = $foreignTomador['bairro'];
            $tomadorPayload['inscricao_municipal'] = '';
        }

        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues(
            $invoice,
            $selectedDocumentItemIds,
            $serviceAmount,
            $itemFiscalProfile['aliquota'] ?? null,
        );
        $ibsCbsPayload = $this->ibsCbsPayloadValues();
        if ($ibsCbsPayload['enabled'] && !(new \Modules\Nfse\Support\NbsEmissionReadiness())->valid((string) ($emissionProfile['codigo_nbs'] ?? ''), true)) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_missing_nbs')));
        }
        $issqnPayload = $this->issqnPayloadValues();

        if (!$foreignTomador['enabled']) {
            $missingAddress = (new TakerAddressReadiness())->missing($tomadorPayload, $ibsCbsPayload);
            if ($missingAddress !== []) {
                return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                    ->with('error', trans('nfse::general.invoices.taker_address_incomplete', [
                        'fields' => implode(', ', $missingAddress),
                    ])));
            }
        }

        $providerContact = $this->providerContact();

        try {
            $dps = (new InvoiceDpsBuilder())->build([
                'cnpjPrestador' => $cnpj,
                'municipioIbge' => $ibge,
                'prestadorTelefone' => $providerContact['telefone'],
                'prestadorEmail' => $providerContact['email'],
                'itemListaServico' => (string) $emissionProfile['item_lista_servico'],
                'codigoTributacaoNacional' => (string) $emissionProfile['codigo_tributacao_nacional'],
                'codigoTributacaoMunicipal' => (string) ($emissionProfile['codigo_tributacao_municipal'] ?? ''),
                'codigoNbs' => (string) ($emissionProfile['codigo_nbs'] ?? ''),
                'valorServico' => number_format($serviceAmount, 2, '.', ''),
                'aliquota' => (string) $emissionProfile['aliquota'],
                'discriminacao' => $this->buildDiscriminacao(
                    $invoice,
                    $itemFiscalProfile['line_items'] ?? [],
                    $customDiscriminacao,
                ),
                'documentoTomador' => $tomadorDocument,
                'nomeTomador' => $this->resolvedTomadorName($invoice),
                'tomador' => $tomadorPayload,
                'foreignTomador' => $foreignTomador,
                'opcaoSimplesNacional' => $opcaoSimplesNacional,
                'issqn' => $issqnPayload,
                'tipoAmbiente' => $sandbox ? 2 : 1,
                'serie' => $this->dpsSerie($invoice),
                'numeroDps' => $this->dpsNumber($invoice),
                'dataCompetencia' => $this->competenceDate($invoice),
                'federal' => $federalPayload,
                'ibsCbs' => $ibsCbsPayload,
            ]);
        } catch (\LogicException $e) {
            $this->safeLogError('NFS-e runtime capability mismatch', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', trans('nfse::general.invoices.emit_runtime_unsupported')),
            );
        }

        if ($substitutionReceipt instanceof NfseReceipt) {
            try {
                $dps = (new SubstitutionDpsBuilder())->build(
                    baseDps: $dps,
                    originalReceipt: $substitutionReceipt,
                    reasonCode: trim((string) $request->input('nfse_substitution_reason', '')),
                    reasonDescription: trim((string) $request->input('nfse_substitution_description', '')),
                );
            } catch (\InvalidArgumentException|\LogicException $e) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.invoices.substitution_invalid', ['reason' => $e->getMessage()])),
                );
            }
        }

        if (is_array($selectedFiscalGroup)) {
            try {
                $client = $this->makeClient($sandbox);
                $groupResult = (new IssueInvoiceFiscalGroup())->issue(
                    client: $client,
                    invoiceId: (int) $invoice->id,
                    baseDps: $dps,
                    group: $selectedFiscalGroup,
                );

                if (!$groupResult['reused'] && $groupResult['remote_receipt'] instanceof ReceiptData) {
                    $this->dispatchPostEmission(
                        $invoice,
                        $groupResult['receipt'],
                        null,
                    );
                }

                $remaining = $this->remainingFiscalGroups($invoice, $fiscalGroups);

                if ($remaining === []) {
                    $this->markInvoiceSentAfterEmission($invoice);
                }

                $message = $remaining === []
                    ? trans('nfse::general.invoices.fiscal_groups_complete')
                    : trans('nfse::general.invoices.fiscal_group_emitted', [
                        'remaining' => count($remaining),
                    ]);

                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)->with('success', $message),
                );
            } catch (SecretStoreException) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.nfse_secret_store_failed')),
                );
            } catch (GatewayException $e) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.nfse_emit_failed'))
                        ->with('nfse_gateway_error_detail', $this->safeIssuanceDetail($e)),
                );
            } catch (NetworkException) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.nfse_emit_failed')),
                );
            } catch (PfxImportException) {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('invoices.show', $invoice)
                        ->with('error', trans('nfse::general.nfse_pfx_import_failed')),
                );
            } finally {
                $this->cleanupClientTransportArtifacts();
            }
        }

        $this->safeLogInfo('NFS-e emission payload', [
            'invoice_id' => $invoice->id,
            'codigo_tributacao_nacional' => $dps->codigoTributacaoNacional,
            'codigo_tributacao_municipal' => $dps->codigoTributacaoMunicipal,
            'opSimpNac' => $dps->opcaoSimplesNacional,
            'aliquota' => $dps->aliquota,
            'tipoAmbiente' => $dps->tipoAmbiente,
            'indicador_tributacao' => $dps->indicadorTributacao,
            'tributacao_federal_mode' => (string) setting('nfse.tributacao_federal_mode', 'per_invoice_amounts'),
            'federal_piscofins_situacao_tributaria' => $dps->federalPiscofinsSituacaoTributaria,
            'federal_piscofins_tipo_retencao' => $dps->federalPiscofinsTipoRetencao,
            'federal_piscofins_base_calculo' => $dps->federalPiscofinsBaseCalculo,
            'federal_piscofins_aliquota_pis' => $dps->federalPiscofinsAliquotaPis,
            'federal_piscofins_valor_pis' => $dps->federalPiscofinsValorPis,
            'federal_piscofins_aliquota_cofins' => $dps->federalPiscofinsAliquotaCofins,
            'federal_piscofins_valor_cofins' => $dps->federalPiscofinsValorCofins,
            'federal_valor_irrf' => $dps->federalValorIrrf,
            'federal_valor_csll' => $dps->federalValorCsll,
            'federal_valor_cp' => $dps->federalValorCp,
            'ibs_cbs_enabled' => $ibsCbsPayload['enabled'],
            'ibs_cbs_ind_final' => $ibsCbsPayload['ibsCbsIndFinal'],
            'ibs_cbs_c_ind_op' => $ibsCbsPayload['ibsCbsCodigoIndicadorOperacao'],
            'ibs_cbs_ind_dest' => $ibsCbsPayload['ibsCbsIndDest'],
            'ibs_cbs_cst' => $ibsCbsPayload['ibsCbsCst'],
            'ibs_cbs_c_class_trib' => $ibsCbsPayload['ibsCbsClassificacaoTributaria'],
            'tributos_fed_p' => $dps->totalTributosPercentualFederal,
            'tributos_est_p' => $dps->totalTributosPercentualEstadual,
            'tributos_mun_p' => $dps->totalTributosPercentualMunicipal,
        ]);

        try {
            $client = $this->makeClient($sandbox);
            $issuance = (new EmissionAttemptJournal())->issue(
                client: $client,
                dps: $dps,
                invoiceId: (int) $invoice->id,
                origin: $substitutionReceipt instanceof NfseReceipt ? 'substitution' : 'manual',
                persist: fn (ReceiptData $authorized): NfseReceipt => $substitutionReceipt instanceof NfseReceipt
                    ? (new ReceiptPersistence())->createReplacement(
                        invoiceId: (int) $invoice->id,
                        receipt: $authorized,
                        resolvedNumber: $this->resolveReceiptNfseNumber($authorized),
                        original: $substitutionReceipt,
                    )
                    : $this->storeEmittedReceipt($invoice, $authorized),
                transmit: fn (): ReceiptData => $substitutionReceipt instanceof NfseReceipt
                    ? (new SubstituteInvoiceNfse())->issue(
                        client: $client,
                        replacementDps: $dps,
                        originalAccessKey: (string) $substitutionReceipt->chave_acesso,
                    )
                    : (new IssueInvoiceNfse())->issue($client, $dps),
            );
            $persistedReceipt = $issuance['receipt'];
        } catch (SecretStoreException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed')));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->safeIssuanceDetail($e);

            $this->safeLogError(
                $gatewayDetail !== null ? 'NFS-e issuance rejected by SEFIN' : 'NFS-e issuance result unconfirmed',
                [
                    'invoice_id' => $invoice->id,
                    'http_status' => $e->httpStatus,
                    'official_code' => (new OfficialIssuanceRejection())->fromException($e)['code'] ?? null,
                ],
            );

            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_emit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail));
        } catch (\JsonException $e) {
            $this->safeLogError('NFS-e issuance failed due invalid non-JSON gateway response', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_emit_failed')));
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e issuance failed after DPS recovery could not resolve the ambiguous outcome', [
                'invoice_id' => $invoice->id,
                'error_class' => $e::class,
                'dps_recovery_supported' => isset($client) && is_callable([$client, 'queryDps']),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_emit_failed')));
        } catch (PfxImportException) {
            $this->cleanupClientTransportArtifacts();

            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        try {
            if (!$issuance['reused']) {
                $email = $this->preparePostEmitEmail($request, $invoice);
                $this->dispatchPostEmission(
                    $invoice,
                    $persistedReceipt,
                    $email,
                );
            }
            $this->markInvoiceSentAfterEmission($invoice);
            $resolvedReceiptNumber = trim((string) $persistedReceipt->nfse_number);
            $successMessage = $substitutionReceipt instanceof NfseReceipt
                ? trans('nfse::general.nfse_substituted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $receipt->chaveAcesso])
                : trans('nfse::general.nfse_emitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $receipt->chaveAcesso]);

            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.show', $invoice)
                    ->with('success', $successMessage),
                ['partial_url' => route('nfse.invoices.emit-success', $invoice)],
            );
        } finally {
            $this->cleanupClientTransportArtifacts();
        }
    }

    protected function markInvoiceSentAfterEmission(Invoice $invoice): void
    {
        event(new DocumentMarkedSent($invoice));
    }

    public function substitute(Invoice $invoice, Request $request): RedirectResponse|JsonResponse
    {
        $receiptId = (int) $request->input('nfse_substitution_receipt_id', 0);

        if ($receiptId <= 0) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('invoices.show', $invoice)
                    ->with('error', trans('nfse::general.invoices.substitution_invalid', ['reason' => 'Missing original receipt.'])),
            );
        }

        return $this->emit($invoice, $request);
    }

    protected function substitutionReceiptFromRequest(Invoice $invoice, ?Request $request): ?NfseReceipt
    {
        if (!$request instanceof Request || !$request->has('nfse_substitution_receipt_id')) {
            return null;
        }

        $receiptId = (int) $request->input('nfse_substitution_receipt_id', 0);
        $receipt = NfseReceipt::query()
            ->whereKey($receiptId)
            ->where('invoice_id', $invoice->id)
            ->first();

        if (!$receipt instanceof NfseReceipt) {
            throw new \InvalidArgumentException('Original NFS-e was not found for this invoice.');
        }

        if ((string) $receipt->status !== 'emitted') {
            throw new \InvalidArgumentException('Only an emitted NFS-e can be substituted.');
        }

        return $receipt;
    }

    public function cancel(Invoice $invoice, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $request = $this->currentRequest($request);
        $receipt = $this->findReceiptForInvoice($invoice);

        $cancellation = $this->cancellationDataForGateway($request);
        $redirect = $this->cancellationRedirect($invoice, $request);

        try {
            $client = $this->makeClient($this->sandboxModeEnabled());
            (new CancelInvoiceNfse())->cancel(
                $client,
                $receipt,
                $cancellation['code'],
                $cancellation['description'],
            );
        } catch (SecretStoreException) {
            return $this->ajaxAwareRedirect(
                $request,
                $redirect
                    ->with('error', trans('nfse::general.nfse_secret_store_failed')),
            );
        } catch (PfxImportException) {
            return $this->ajaxAwareRedirect(
                $request,
                $redirect
                    ->with('error', trans('nfse::general.nfse_pfx_import_failed')),
            );
        } catch (GatewayException $e) {
            $gatewayDetail = $this->gatewayErrorDetail($e);

            if ($this->isCancellationAlreadyRegistered($e, $gatewayDetail)) {
                $receipt->update(['status' => 'cancelled']);

                $this->safeLogInfo('NFS-e cancellation already registered at SEFIN; local receipt marked as cancelled', [
                    'invoice_id' => $invoice->id,
                    'http_status' => $e->httpStatus,
                    'upstream_payload' => $e->upstreamPayload,
                    'gateway_detail' => $gatewayDetail,
                ]);

                return $this->ajaxAwareRedirect(
                    $request,
                    $redirect
                        ->with('success', trans('nfse::general.nfse_cancelled')),
                );
            }

            $this->safeLogError('NFS-e cancellation rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
            ]);

            return $this->ajaxAwareRedirect(
                $request,
                $redirect
                    ->with('error', trans('nfse::general.nfse_cancel_failed'))
                    ->with('nfse_gateway_error_detail', $gatewayDetail),
            );
        } finally {
            $this->cleanupClientTransportArtifacts();
        }

        return $this->ajaxAwareRedirect(
            $request,
            $redirect
                ->with('success', trans('nfse::general.nfse_cancelled')),
        );
    }

    protected function cancellationRedirect(Invoice $invoice, ?Request $request = null): RedirectResponse
    {
        $request = $this->currentRequest($request);
        $target = trim((string) ($request?->input('redirect_after_cancel', '') ?? ''));

        return match ($target) {
            'invoice_show' => redirect()->route('invoices.show', $invoice),
            'nfse_show' => redirect()->route('invoices.show', $invoice),
            default => redirect()->route('invoices.show', $invoice),
        };
    }

    /**
     * @return array{code:string,description:string}
     */
    protected function cancellationDataForGateway(?Request $request = null): array
    {
        $request = $this->currentRequest($request);
        $allowedReasons = ['1', '2', '9'];

        if (!$request instanceof Request) {
            return [
                'code' => '9',
                'description' => (string) trans('nfse::general.cancel_motivo_default'),
            ];
        }

        $allInput = method_exists($request, 'all') && is_array($request->all())
            ? $request->all()
            : [];
        $isDeleteMethod = method_exists($request, 'isMethod')
            ? $request->isMethod('delete')
            : false;
        $hasStructuredCancellationData = array_key_exists('cancel_reason', $allInput)
            || array_key_exists('cancel_justification', $allInput);

        if (!$isDeleteMethod && !$hasStructuredCancellationData) {
            return [
                'code' => '9',
                'description' => (string) trans('nfse::general.cancel_motivo_default'),
            ];
        }

        $validated = $request->validate(
            [
                'cancel_reason' => ['required', 'string', 'in:' . implode(',', $allowedReasons)],
                'cancel_justification' => ['required', 'string', 'min:15', 'max:255'],
            ],
            [
                'cancel_reason.required' => (string) trans('nfse::general.invoices.cancel_reason_required'),
                'cancel_reason.in' => (string) trans('nfse::general.invoices.cancel_reason_invalid'),
                'cancel_justification.required' => (string) trans('nfse::general.invoices.cancel_justification_required'),
                'cancel_justification.min' => (string) trans('nfse::general.invoices.cancel_justification_length'),
                'cancel_justification.max' => (string) trans('nfse::general.invoices.cancel_justification_length'),
            ],
        );

        return [
            'code' => trim((string) $validated['cancel_reason']),
            'description' => trim((string) $validated['cancel_justification']),
        ];
    }

    protected function currentRequest(?Request $request = null): ?Request
    {
        if ($request instanceof Request) {
            return $request;
        }

        if (function_exists('app')) {
            $application = app();

            if (is_object($application) && method_exists($application, 'bound') && $application->bound('request')) {
                $resolvedRequest = app('request');

                return $resolvedRequest instanceof Request ? $resolvedRequest : null;
            }

            if ($application instanceof Request) {
                return $application;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function cancellationReasonOptions(): array
    {
        $localized = trans('nfse::general.invoices.cancel_reason_options');

        if (!is_array($localized)) {
            return ['Erro na emissão', 'Serviço não prestado', 'Outros'];
        }

        $normalized = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $localized), static function (string $value): bool {
            return $value !== '';
        }));

        return $normalized !== [] ? $normalized : ['Erro na emissão', 'Serviço não prestado', 'Outros'];
    }

    public function refresh(Invoice $invoice): RedirectResponse
    {
        $receipt = $this->findReceiptForInvoice($invoice);

        if (($receipt->status ?? '') === 'cancelled') {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.refresh_not_allowed_for_cancelled'));
        }

        try {
            $client = $this->makeClient($this->sandboxModeEnabled());
            $updatedReceipt = (new RefreshInvoiceNfse())->refresh($client, $receipt);
            $resolvedReceiptNumber = (new ReceiptNumberResolver())->resolve($updatedReceipt);

            try {
                $this->dispatchPostEmission($invoice, $receipt, null);
            } finally {
                $this->cleanupClientTransportArtifacts();
            }

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('success', trans('nfse::general.nfse_refreshed', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $updatedReceipt->chaveAcesso]));
        } catch (SecretStoreException) {
            $this->cleanupClientTransportArtifacts();

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
        } catch (PfxImportException) {
            $this->cleanupClientTransportArtifacts();

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed'));
        } catch (\Throwable $e) {
            $this->cleanupClientTransportArtifacts();
            $this->safeLogError('NFS-e refresh failed', [
                'invoice_id' => $invoice->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_refresh_failed'));
        }
    }

    public function refreshAll(): RedirectResponse
    {
        $updated = 0;
        $failed = 0;

        try {
            $client = $this->makeClient($this->sandboxModeEnabled());
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.index')
                ->with('warning', trans('nfse::general.nfse_secret_store_failed'));
        } catch (PfxImportException) {
            return redirect()->route('nfse.invoices.index')
                ->with('warning', trans('nfse::general.nfse_pfx_import_failed'));
        }

        try {
            foreach ($this->refreshableReceipts() as $receipt) {
                try {
                    $updatedReceipt = $client->query($receipt->chave_acesso);
                    $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($updatedReceipt);

                    $receipt->update([
                        'nfse_number' => $resolvedReceiptNumber,
                        'chave_acesso' => $updatedReceipt->chaveAcesso,
                        'data_emissao' => $updatedReceipt->dataEmissao,
                        'codigo_verificacao' => $updatedReceipt->codigoVerificacao,
                        'status' => 'emitted',
                    ]);

                    $updated++;
                } catch (\Throwable) {
                    $failed++;
                }
            }
        } finally {
            $this->cleanupClientTransportArtifacts();
        }

        if ($failed === 0) {
            return redirect()->route('nfse.invoices.index')
                ->with('success', trans('nfse::general.nfse_refresh_all_done', ['count' => $updated]));
        }

        return redirect()->route('nfse.invoices.index')
            ->with('warning', trans('nfse::general.nfse_refresh_all_partial', [
                'updated' => $updated,
                'failed' => $failed,
            ]));
    }

    public function reemit(Invoice $invoice, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $request = $this->currentRequest($request);
        $this->ensureInvoiceRelationsLoaded($invoice);

        if (!$this->invoiceHasLineItems($invoice)) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_no_items')));
        }

        $federalTaxReadiness = $this->federalTaxReadinessForInvoice($invoice);

        if (($federalTaxReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', $this->emitBlockedFederalTaxMessage($federalTaxReadiness['missing'] ?? [])));
        }

        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);
        $this->persistDefaultDescriptionFromRequest($request);

        $receipt = $this->findReceiptForInvoice($invoice);

        if (($receipt->status ?? '') !== 'cancelled') {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.nfse_reemit_not_cancelled')));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready')));
        }

        $sandboxReemit = $this->sandboxModeEnabled();
        $tomadorDocument = $this->resolvedTomadorDocument($invoice);
        $tomadorPayload = $this->tomadorPayload($invoice->contact, $invoice);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $serviceAmount = $this->invoiceServiceAmount($invoice);
        $federalPayload = $this->federalPayloadValues($invoice, null, $serviceAmount);
        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        if (($itemFiscalProfile['requires_split'] ?? false) === true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.reemit_requires_separate_fiscal_groups')));
        }

        // Reemission must not collapse distinct persisted RTC operation types
        // into a synthetic ordinary-LC116 invoice.
        foreach ($this->invoiceFiscalGroups($invoice) as $fiscalGroup) {
            $category = trim((string) ($fiscalGroup['rtc_supply_category'] ?? ''));
            if ($category !== '' && $category !== 'ordinary_lc116') {
                return $this->ajaxAwareRedirect(
                    $request,
                    redirect()->route('nfse.invoices.show', $invoice)
                        ->with('error', $this->rtcUnsupportedCategoryMessage($category)),
                );
            }
        }

        $fiscalProfileReadiness = (new FiscalProfileEmissionReadiness())->evaluate($itemFiscalProfile);

        if (($fiscalProfileReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.show', $invoice)
                    ->with('error', $this->invalidFiscalProfileMessage($fiscalProfileReadiness)),
            );
        }

        $nfseSettings = setting('nfse', []);
        $nfseSettings = is_array($nfseSettings) ? $nfseSettings : [];
        $ibsCbsReadiness = (new IbsCbsEmissionReadiness())->evaluate(
            competenceDate: (string) ($this->competenceDate($invoice) ?? ''),
            opcaoSimplesNacional: $opcaoSimplesNacional,
            itemListaServico: (string) ($itemFiscalProfile['item_lista_servico'] ?? ''),
            settings: $nfseSettings,
        );

        if (($ibsCbsReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.show', $invoice)
                    ->with('error', $this->ibsCbsBlockedMessage($ibsCbsReadiness)),
            );
        }

        $ibsCbsPayload = $this->ibsCbsPayloadValues();
        if ($ibsCbsPayload['enabled'] && !(new \Modules\Nfse\Support\NbsEmissionReadiness())->valid((string) ($itemFiscalProfile['codigo_nbs'] ?? ''), true)) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_blocked_missing_nbs')));
        }

        $missingAddress = (new TakerAddressReadiness())->missing($tomadorPayload, $ibsCbsPayload);
        if ($missingAddress !== []) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.taker_address_incomplete', [
                    'fields' => implode(', ', $missingAddress),
                ])));
        }

        try {
            $dps = $this->makeDpsData([
            'cnpjPrestador' => (string) setting('nfse.cnpj_prestador'),
            'municipioIbge' => (string) setting('nfse.municipio_ibge'),
            'itemListaServico' => (string) $itemFiscalProfile['item_lista_servico'],
            'codigoTributacaoNacional' => (string) $itemFiscalProfile['codigo_tributacao_nacional'],
            'codigoTributacaoMunicipal' => (string) ($itemFiscalProfile['codigo_tributacao_municipal'] ?? ''),
            'codigoNbs' => (string) ($itemFiscalProfile['codigo_nbs'] ?? ''),
            'valorServico' => number_format($serviceAmount, 2, '.', ''),
            'aliquota' => (string) $itemFiscalProfile['aliquota'],
            'discriminacao' => $this->buildDiscriminacao($invoice, $itemFiscalProfile['line_items'] ?? [], $customDiscriminacao),
            'documentoTomador' => $tomadorDocument,
            'nomeTomador' => $this->resolvedTomadorName($invoice),
            'tomadorCodigoMunicipio' => $tomadorPayload['codigo_municipio'],
            'tomadorCep' => $tomadorPayload['cep'],
            'tomadorLogradouro' => $tomadorPayload['logradouro'],
            'tomadorNumero' => $tomadorPayload['numero'],
            'tomadorComplemento' => $tomadorPayload['complemento'],
            'tomadorBairro' => $tomadorPayload['bairro'],
            'tomadorInscricaoMunicipal' => $tomadorPayload['inscricao_municipal'],
            'tomadorTelefone' => $tomadorPayload['telefone'],
            'tomadorEmail' => $tomadorPayload['email'],
            'opcaoSimplesNacional' => $opcaoSimplesNacional,
            'tipoAmbiente' => $sandboxReemit ? 2 : 1,
            'serie' => $this->dpsSerie($invoice),
            'numeroDps' => $this->dpsNumberForReemit($invoice),
            'dataCompetencia' => $this->competenceDate($invoice),
            'indicadorTributacao' => $federalPayload['indicadorTributacao'],
            'totalTributosPercentualFederal' => $federalPayload['totalTributosPercentualFederal'],
            'totalTributosPercentualEstadual' => $federalPayload['totalTributosPercentualEstadual'],
            'totalTributosPercentualMunicipal' => $federalPayload['totalTributosPercentualMunicipal'],
            'federalPiscofinsSituacaoTributaria' => $federalPayload['federalPiscofinsSituacaoTributaria'],
            'federalPiscofinsTipoRetencao' => $federalPayload['federalPiscofinsTipoRetencao'],
            'federalPiscofinsBaseCalculo' => $federalPayload['federalPiscofinsBaseCalculo'],
            'federalPiscofinsAliquotaPis' => $federalPayload['federalPiscofinsAliquotaPis'],
            'federalPiscofinsValorPis' => $federalPayload['federalPiscofinsValorPis'],
            'federalPiscofinsAliquotaCofins' => $federalPayload['federalPiscofinsAliquotaCofins'],
            'federalPiscofinsValorCofins' => $federalPayload['federalPiscofinsValorCofins'],
            'federalValorIrrf' => $federalPayload['federalValorIrrf'],
            'federalValorCsll' => $federalPayload['federalValorCsll'],
            'federalValorCp' => $federalPayload['federalValorCp'],
            'ibsCbsFinalidade' => $ibsCbsPayload['ibsCbsFinalidade'],
            'ibsCbsIndFinal' => $ibsCbsPayload['ibsCbsIndFinal'],
            'ibsCbsCodigoIndicadorOperacao' => $ibsCbsPayload['ibsCbsCodigoIndicadorOperacao'],
            'ibsCbsIndDest' => $ibsCbsPayload['ibsCbsIndDest'],
            'ibsCbsCst' => $ibsCbsPayload['ibsCbsCst'],
            'ibsCbsClassificacaoTributaria' => $ibsCbsPayload['ibsCbsClassificacaoTributaria'],
            ], array_values(array_filter([
                'codigoTributacaoMunicipal',
                $ibsCbsPayload['enabled'] ? 'codigoNbs' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsFinalidade' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsIndFinal' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsCodigoIndicadorOperacao' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsIndDest' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsCst' : null,
                $ibsCbsPayload['enabled'] ? 'ibsCbsClassificacaoTributaria' : null,
            ])));
        } catch (\LogicException $e) {
            $this->safeLogError('NFS-e runtime capability mismatch during reissuance', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.invoices.emit_runtime_unsupported')));
        }

        try {
            $client = $this->makeClient($sandboxReemit);
            $issuance = (new EmissionAttemptJournal())->issue(
                client: $client,
                dps: $dps,
                invoiceId: (int) $invoice->id,
                origin: 'reemit',
                persist: fn (ReceiptData $authorized): NfseReceipt => (new ReceiptPersistence())->createReemitted(
                    invoiceId: (int) $invoice->id,
                    receipt: $authorized,
                    resolvedNumber: $this->resolveReceiptNfseNumber($authorized),
                ),
                transmit: fn (): ReceiptData => (new IssueInvoiceNfse())->issue($client, $dps),
            );
            $persistedReceipt = $issuance['receipt'];
        } catch (SecretStoreException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed')));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->safeIssuanceDetail($e);

            $this->safeLogError(
                $gatewayDetail !== null ? 'NFS-e reissuance rejected by SEFIN' : 'NFS-e reissuance result unconfirmed',
                [
                    'invoice_id' => $invoice->id,
                    'http_status' => $e->httpStatus,
                    'official_code' => (new OfficialIssuanceRejection())->fromException($e)['code'] ?? null,
                ],
            );

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail));
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e reissuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'error_class' => $e::class,
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed')));
        } catch (PfxImportException) {
            $this->cleanupClientTransportArtifacts();

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        try {
            if (!$issuance['reused']) {
                $email = $this->preparePostEmitEmail($request, $invoice);
                $this->dispatchPostEmission($invoice, $persistedReceipt, $email);
            }
            $resolvedReceiptNumber = trim((string) $persistedReceipt->nfse_number);

            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.show', $invoice)
                    ->with('success', trans('nfse::general.nfse_reemitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $newReceipt->chaveAcesso])),
                ['partial_url' => route('nfse.invoices.emit-success', $invoice)],
            );
        } finally {
            $this->cleanupClientTransportArtifacts();
        }
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<string> $lineItems
     */
    protected function buildDiscriminacao(Invoice $invoice, array $lineItems = [], ?string $customDescription = null): string
    {
        if ($customDescription !== null) {
            return $customDescription;
        }

        $defaultDescription = $this->defaultEmitDescription();

        if ($defaultDescription !== null) {
            $serviceDescription = $this->normalizeDescriptionText((string) ($invoice->description ?? ''));

            if ($serviceDescription === null && $lineItems !== []) {
                $serviceDescription = $this->normalizeDescriptionText(implode(' | ', $lineItems));
            }

            if ($serviceDescription === null) {
                $serviceDescription = $this->normalizeDescriptionText(implode(' | ', $invoice->items->pluck('name')->toArray()));
            }

            return $serviceDescription !== null && $serviceDescription !== $defaultDescription
                ? $serviceDescription . "\n\n" . $defaultDescription
                : $defaultDescription;
        }

        if ($lineItems !== []) {
            return implode(' | ', $lineItems);
        }

        return implode(' | ', $invoice->items->pluck('name')->toArray())
            ?: $invoice->description
            ?: trans('nfse::general.service_default');
    }

    protected function defaultEmitDescription(): ?string
    {
        $rawValue = setting(self::INVOICE_NOTES_SETTING_KEY, '');

        if (!is_string($rawValue)) {
            return null;
        }

        return $this->normalizeDescriptionText($rawValue);
    }

    protected function persistDefaultDescriptionFromRequest(?Request $request): void
    {
        if (!$request instanceof Request) {
            return;
        }

        if (!$request->boolean('nfse_save_default_description', false)) {
            return;
        }

        $rawValue = $request->input('nfse_discriminacao_custom', '');

        if (!is_string($rawValue)) {
            return;
        }

        $valueToPersist = $this->normalizeDescriptionText($rawValue) ?? '';

        setting([self::INVOICE_NOTES_SETTING_KEY => $valueToPersist]);

        $settings = setting();

        if (is_object($settings) && is_callable([$settings, 'save'])) {
            $settings->save();
        }
    }

    protected function customDiscriminacaoFromRequest(?Request $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        $rawValue = $request->input('nfse_discriminacao_custom');

        if (!is_string($rawValue)) {
            return null;
        }

        return $this->normalizeDescriptionText($rawValue);
    }


    protected function ajaxAwareRedirect(?Request $request, RedirectResponse $redirect, array $extra = []): RedirectResponse|JsonResponse
    {
        if ($request === null && function_exists('request')) {
            try {
                $currentRequest = request();

                if ($currentRequest instanceof Request) {
                    $request = $currentRequest;
                }
            } catch (\Throwable) {
                // Ignore container-less contexts used by isolated unit tests.
            }
        }

        $forcedAjax = $request instanceof Request && $request->boolean('nfse_force_ajax', false);

        if (!$request instanceof Request || (!$request->isXmlHttpRequest() && !$forcedAjax)) {
            return $redirect;
        }

        $flash = $this->redirectFlashPayload($redirect);
        $hasSuccess = isset($flash['success']);
        $hasError = !$hasSuccess && (isset($flash['error']) || isset($flash['warning']));

        if ($hasError) {
            $message = (string) ($flash['error'] ?? $flash['warning'] ?? '');
            $gatewayDetail = trim((string) ($flash['nfse_gateway_error_detail'] ?? ''));

            if ($gatewayDetail !== '') {
                $message = trim($message) !== ''
                    ? $message . ' Detalhe SEFIN: ' . $gatewayDetail
                    : 'Detalhe SEFIN: ' . $gatewayDetail;
            }

            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $message,
                'redirect' => false,
                'data' => null,
            ]);
        }

        if (isset($flash['success'])) {
            session()->flash('success', $flash['success']);
        }

        if (isset($flash['info'])) {
            session()->flash('info', $flash['info']);
        }

        if (isset($flash['warning'])) {
            session()->flash('warning', $flash['warning']);
        }

        return response()->json(array_merge([
            'success' => true,
            'error' => false,
            'message' => (string) ($flash['success'] ?? ''),
            'redirect' => $redirect->getTargetUrl(),
            'data' => null,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function redirectFlashPayload(RedirectResponse $redirect): array
    {
        $flash = [];

        $redirectVars = get_object_vars($redirect);

        if (isset($redirectVars['flash']) && is_array($redirectVars['flash'])) {
            $flash = $redirectVars['flash'];
        }

        $session = null;

        if (method_exists($redirect, 'getSession')) {
            try {
                $session = $redirect->getSession();
            } catch (\Throwable) {
                $session = null;
            }
        }

        if ($session === null) {
            if (function_exists(__NAMESPACE__ . '\\session')) {
                try {
                    $session = session();
                } catch (\Throwable) {
                    $session = null;
                }
            } elseif (function_exists('session')) {
                try {
                    $session = \session();
                } catch (\Throwable) {
                    $session = null;
                }
            }
        }

        foreach (['success', 'error', 'warning', 'info', 'nfse_gateway_error_detail'] as $key) {
            if (array_key_exists($key, $flash)) {
                continue;
            }

            if (!is_object($session) || !is_callable([$session, 'get'])) {
                continue;
            }

            try {
                $value = $session->get($key);
            } catch (\Throwable) {
                $value = null;
            }

            if ($value !== null) {
                $flash[$key] = $value;
            }
        }

        return $flash;
    }
    protected function normalizeDescriptionText(string $value): ?string
    {
        $normalizedLineBreaks = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
        $normalizedLineBreaks = str_replace(["\r\n", "\r"], "\n", $normalizedLineBreaks);
        $lines = explode("\n", $normalizedLineBreaks);
        $normalizedLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            $collapsedSpaces = preg_replace('/[ \t]+/', ' ', $trimmedLine);
            $normalizedLines[] = is_string($collapsedSpaces) ? $collapsedSpaces : $trimmedLine;
        }

        while ($normalizedLines !== [] && $normalizedLines[0] === '') {
            array_shift($normalizedLines);
        }

        while ($normalizedLines !== [] && $normalizedLines[array_key_last($normalizedLines)] === '') {
            array_pop($normalizedLines);
        }

        if ($normalizedLines === []) {
            return null;
        }

        return implode("\n", $normalizedLines);
    }

    /**
     * @return array{item_lista_servico:string,codigo_tributacao_nacional:string,codigo_tributacao_municipal:string,aliquota:string,line_items:list<string>,requires_split:bool}
     */
    protected function resolveInvoiceFiscalProfileFromItems(Invoice $invoice, ?object $defaultService = null): array
    {
        $items = $this->invoiceItemsAsArray($invoice);
        $itemIds = [];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;

            if ($itemId > 0) {
                $itemIds[] = $itemId;
            }
        }

        $itemIds = array_values(array_unique($itemIds));
        $companyId = is_numeric($invoice->company_id ?? null) ? (int) $invoice->company_id : $this->resolveCompanyId();

        return (new InvoiceFiscalProfileSelector())->select(
            items: $items,
            profileMap: $this->invoiceItemFiscalProfileMap($companyId, $itemIds),
            taxRateMap: $this->invoiceItemTaxRateMap($itemIds),
            defaultServiceCode: $this->itemListaServico($defaultService),
            defaultNationalCode: $this->nationalTaxCode($defaultService),
            defaultRate: $this->normalizedAliquota($defaultService),
            unnamedItemLabel: (string) trans('general.na'),
        );
    }

    /**
     * @return list<array{
     *   key:string,
     *   item_lista_servico:string,
     *   codigo_tributacao_nacional:string,
     *   aliquota:string,
     *   amount:string,
     *   items:list<array{document_item_id:int,item_id:int,name:string,amount:string}>
     * }>
     */
    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    protected function annotateFiscalGroupsWithReceiptState(Invoice $invoice, array $groups): array
    {
        return (new FiscalGroupReceiptState())->annotate((int) $invoice->id, $groups);
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    protected function remainingFiscalGroups(Invoice $invoice, array $groups): array
    {
        return (new FiscalGroupReceiptState())->remaining((int) $invoice->id, $groups);
    }


    protected function invoiceFiscalGroups(Invoice $invoice): array
    {
        $items = $this->invoiceItemsAsArray($invoice);

        foreach ($items as $item) {
            if (!is_scalar($item['total'] ?? null)) {
                return [];
            }
        }

        $itemIds = [];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;

            if ($itemId > 0) {
                $itemIds[] = $itemId;
            }
        }

        $itemIds = array_values(array_unique($itemIds));
        $companyId = is_numeric($invoice->company_id ?? null)
            ? (int) $invoice->company_id
            : $this->resolveCompanyId();

        return (new InvoiceFiscalGroupBuilder())->build(
            items: $items,
            profileMap: $this->invoiceItemFiscalProfileMap($companyId, $itemIds),
            taxRateMap: $this->invoiceItemTaxRateMap($itemIds),
            defaultServiceCode: $this->itemListaServico(),
            defaultNationalCode: $this->nationalTaxCode(),
            defaultRate: $this->normalizedAliquota(),
        );
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, array{item_lista_servico:string,codigo_tributacao_nacional:string,codigo_tributacao_municipal:string}>
     */
    protected function invoiceItemFiscalProfileMap(int $companyId, array $itemIds): array
    {
        if ($companyId <= 0 || $itemIds === []) {
            return [];
        }

        try {
            return ItemFiscalProfile::query()
                ->where('company_id', $companyId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->mapWithKeys(static function (ItemFiscalProfile $profile): array {
                    $itemId = (int) ($profile->item_id ?? 0);

                    if ($itemId <= 0) {
                        return [];
                    }

                    return [
                        $itemId => [
                            'item_lista_servico' => Lc116Code::normalize($profile->item_lista_servico ?? ''),
                            'codigo_tributacao_nacional' => preg_replace('/\D+/', '', (string) ($profile->codigo_tributacao_nacional ?? '')) ?: '',
                            'codigo_tributacao_municipal' => preg_replace('/\D+/', '', (string) ($profile->codigo_tributacao_municipal ?? '')) ?: '',
                            'codigo_nbs' => trim((string) ($profile->codigo_nbs ?? '')),
                            'rtc_supply_category' => trim((string) ($profile->rtc_supply_category ?? '')),
                        ],
                    ];
                })
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, string>
     */
    protected function invoiceItemTaxRateMap(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        try {
            $items = CommonItem::query()
                ->whereIn('id', $itemIds)
                ->with(['taxes.tax'])
                ->get();

            $rates = [];

            foreach ($items as $item) {
                $itemId = (int) ($item->id ?? 0);

                if ($itemId <= 0) {
                    continue;
                }

                $rate = 0.0;

                foreach ($item->taxes ?? [] as $itemTax) {
                    $tax = $itemTax->tax ?? null;

                    if (!is_object($tax) || !is_numeric($tax->rate ?? null)) {
                        continue;
                    }

                    $type = strtolower((string) ($tax->type ?? 'normal'));

                    if (in_array($type, ['fixed', 'withholding'], true)) {
                        continue;
                    }

                    $rate += (float) $tax->rate;
                }

                if ($rate > 0) {
                    $rates[$itemId] = number_format($rate, 2, '.', '');
                }
            }

            return $rates;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id:int,label:string,is_default:bool}>
     */
    protected function availableInvoiceServices(Invoice $invoice): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function invoiceItemsAsArray(Invoice $invoice): array
    {
        $items = $invoice->items;

        if (is_object($items) && method_exists($items, 'toArray')) {
            $arrayItems = $items->toArray();

            return is_array($arrayItems) ? $arrayItems : [];
        }

        if (is_array($items)) {
            return $items;
        }

        return [];
    }

    protected function invoiceHasLineItems(Invoice $invoice): bool
    {
        $items = $this->invoiceItemsAsArray($invoice);

        if ($items !== []) {
            return true;
        }

        if (method_exists($invoice, 'items')) {
            try {
                $relation = $invoice->items();

                if (is_object($relation) && method_exists($relation, 'exists')) {
                    return (bool) $relation->exists();
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array{isReady: bool, missing: list<string>}
     */
    /**
     * @param list<int>|null $documentItemIds
     * @return array{isReady: bool, missing: list<string>}
     */
    /**
     * @param list<int>|null $documentItemIds
     * @return array{isReady: bool, missing: list<string>}
     */
    protected function federalTaxReadinessForInvoice(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): array {
        $policy = new FederalTaxReadiness();
        $requiredBuckets = $policy->requiredBuckets(
            enforce: setting('nfse.enforce_item_federal_taxes', true),
            piscofinsSituation: $this->normalizedFederalSelectValue(
                setting('nfse.federal_piscofins_situacao_tributaria', ''),
            ),
            retentionType: $this->normalizedFederalSelectValue(
                setting('nfse.federal_piscofins_tipo_retencao', ''),
            ),
        );

        if ($requiredBuckets === []) {
            return $policy->evaluate([], []);
        }

        return $policy->evaluate(
            $this->invoiceFederalTaxSnapshot(
                $invoice,
                $this->invoiceServiceAmount($invoice, $documentItemIds, $amountOverride),
                $documentItemIds,
            ),
            $requiredBuckets,
        );
    }

    /**
     * @param list<string> $missingBuckets
     */
    protected function emitBlockedFederalTaxMessage(array $missingBuckets): string
    {
        if ($missingBuckets === []) {
            return (string) trans('nfse::general.invoices.emit_blocked_missing_federal_taxes');
        }

        $labels = array_map(function (string $bucket): string {
            $translated = trim((string) trans('nfse::general.invoices.federal_tax_labels.' . $bucket));

            if ($translated !== '' && $translated !== 'nfse::general.invoices.federal_tax_labels.' . $bucket) {
                return $translated;
            }

            return strtoupper($bucket);
        }, $missingBuckets);

        return (string) trans('nfse::general.invoices.emit_blocked_missing_federal_taxes_with_list', [
            'taxes' => implode(', ', $labels),
        ]);
    }

    protected function annotatePendingInvoicesFederalReadiness(mixed $pendingInvoices): mixed
    {
        if (is_object($pendingInvoices) && is_callable([$pendingInvoices, 'getCollection']) && is_callable([$pendingInvoices, 'setCollection'])) {
            $collection = $pendingInvoices->getCollection();

            if (is_object($collection) && is_callable([$collection, 'map'])) {
                $pendingInvoices->setCollection($collection->map(fn ($invoice) => $this->attachPendingInvoiceFederalReadiness($invoice)));

                return $pendingInvoices;
            }
        }

        if (is_array($pendingInvoices)) {
            return array_map(fn ($invoice) => $this->attachPendingInvoiceFederalReadiness($invoice), $pendingInvoices);
        }

        return $pendingInvoices;
    }

    protected function attachPendingInvoiceFederalReadiness(mixed $invoice): mixed
    {
        if (!$invoice instanceof Invoice) {
            return $invoice;
        }

        $readiness = $this->federalTaxReadinessForInvoice($invoice);

        $invoice->nfse_emit_ready = $readiness['isReady'];
        $invoice->nfse_emit_block_reason = $readiness['isReady']
            ? ''
            : $this->emitBlockedFederalTaxMessage($readiness['missing']);

        return $invoice;
    }

    /**
     * @return array{enabled: bool, nif: string, codigo_nao_nif: ?int, pais_codigo: string, codigo_postal: string, cidade: string, estado: string, logradouro: string, numero: string, complemento: string, bairro: string}
     */
    protected function foreignTomadorPayloadFromRequest(?Request $request, Invoice $invoice, array $nationalPayload): array
    {
        $enabled = $request?->boolean('nfse_tomador_foreign', false) ?? false;

        if (!$enabled) {
            return [
                'enabled' => false,
                'nif' => '',
                'codigo_nao_nif' => null,
                'pais_codigo' => '',
                'codigo_postal' => '',
                'cidade' => '',
                'estado' => '',
                'logradouro' => '',
                'numero' => '',
                'complemento' => '',
                'bairro' => '',
            ];
        }

        $nif = trim((string) $request->input('nfse_tomador_nif', ''));
        $naoNifRaw = trim((string) $request->input('nfse_tomador_nao_nif', ''));
        $codigoNaoNif = $naoNifRaw === '' ? null : (int) $naoNifRaw;

        if ($nif !== '' && $codigoNaoNif !== null) {
            throw new \InvalidArgumentException('informe NIF ou motivo para ausência de NIF, não ambos');
        }

        if ($nif === '' && $codigoNaoNif === null) {
            throw new \InvalidArgumentException('informe o NIF ou o motivo para ausência de NIF');
        }

        if (mb_strlen($nif) > 40) {
            throw new \InvalidArgumentException('o NIF deve ter no máximo 40 caracteres');
        }

        if ($codigoNaoNif !== null && !in_array($codigoNaoNif, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('o motivo para ausência de NIF deve ser 0, 1 ou 2');
        }

        $pais = strtoupper(trim((string) $request->input('nfse_tomador_country', '')));
        $codigoPostal = trim((string) $request->input('nfse_tomador_postal_code', ''));
        $cidade = trim((string) $request->input('nfse_tomador_city', ''));
        $estado = trim((string) $request->input('nfse_tomador_region', ''));
        $logradouro = trim((string) $request->input('nfse_tomador_street', (string) ($nationalPayload['logradouro'] ?? '')));
        $numero = trim((string) $request->input('nfse_tomador_number', (string) ($nationalPayload['numero'] ?? '')));
        $complemento = trim((string) $request->input('nfse_tomador_complement', (string) ($nationalPayload['complemento'] ?? '')));
        $bairro = trim((string) $request->input('nfse_tomador_district', (string) ($nationalPayload['bairro'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $pais) !== 1 || $pais === 'BR') {
            throw new \InvalidArgumentException('informe um código ISO de país estrangeiro com 2 letras');
        }

        foreach ([
            'código postal' => [$codigoPostal, 11],
            'cidade' => [$cidade, 60],
            'estado/província/região' => [$estado, 60],
            'logradouro' => [$logradouro, 255],
            'número' => [$numero, 60],
            'bairro/distrito' => [$bairro, 60],
        ] as $label => [$value, $maxLength]) {
            if ($value === '') {
                throw new \InvalidArgumentException('informe ' . $label . ' do tomador estrangeiro');
            }

            if (mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException($label . ' excede o tamanho máximo permitido');
            }
        }

        return [
            'enabled' => true,
            'nif' => $nif,
            'codigo_nao_nif' => $codigoNaoNif,
            'pais_codigo' => $pais,
            'codigo_postal' => $codigoPostal,
            'cidade' => $cidade,
            'estado' => $estado,
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => $complemento,
            'bairro' => $bairro,
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    protected function servicePreviewTakerDefaults(Invoice $invoice): array
    {
        $payload = $this->tomadorPayload($invoice->contact, $invoice);
        $country = strtoupper($this->contactOrInvoiceStringField(
            $invoice->contact,
            $invoice,
            ['country_code', 'country'],
            ['contact_country_code', 'contact_country'],
        ));

        if (strlen($country) !== 2) {
            $country = '';
        }

        return [
            'foreign' => false,
            'nif' => '',
            'nao_nif' => '',
            'country' => $country !== 'BR' ? $country : '',
            'postal_code' => '',
            'city' => '',
            'region' => '',
            'street' => (string) ($payload['logradouro'] ?? ''),
            'number' => (string) ($payload['numero'] ?? ''),
            'complement' => (string) ($payload['complemento'] ?? ''),
            'district' => (string) ($payload['bairro'] ?? ''),
        ];
    }

    protected function normalizedTomadorDocument(?string $document): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) $document) ?: '');

        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^\d{11}$/', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    protected function resolvedTomadorDocument(Invoice $invoice): string
    {
        return (new InvoiceTakerResolver())->document($invoice);
    }

    protected function resolvedTomadorName(Invoice $invoice): string
    {
        return (new InvoiceTakerResolver())->name($invoice);
    }

    protected function makeDpsData(array $payload, array $requiredFields = []): DpsData
    {
        return (new RuntimeDpsFactory())->make($payload, $requiredFields);
    }

    /**
     * @param array{issues?:list<string>,source_versions?:array<string,string>} $readiness
     */
    protected function rtcUnsupportedCategoryMessage(string $category): string
    {
        $label = trans('nfse::general.items.rtc_' . $category);
        return (string) trans('nfse::general.invoices.rtc_supply_category_unsupported', [
            'category' => $label !== 'nfse::general.items.rtc_' . $category ? $label : $category,
        ]);
    }

    protected function invalidFiscalProfileMessage(array $readiness, ?Invoice $invoice = null): string
    {
        $issueCodes = is_array($readiness['issues'] ?? null)
            ? array_values(array_map('strval', $readiness['issues']))
            : [];
        $issues = array_map(
            static fn (string $issue): string => (string) trans('nfse::general.items.validation.' . $issue),
            $issueCodes,
        );
        $versions = is_array($readiness['source_versions'] ?? null)
            ? array_values(array_filter($readiness['source_versions'], 'is_string'))
            : [];
        $version = $versions !== [] ? implode(', ', array_unique($versions)) : 'unknown';

        if ($invoice instanceof Invoice && in_array('missing_national_code', $issueCodes, true)) {
            $items = $this->itemsMissingNationalTaxCode($invoice);

            if ($items !== []) {
                return (string) trans('nfse::general.invoices.emit_blocked_missing_national_code_items', [
                    'items' => implode(', ', $items),
                    'version' => $version,
                ]);
            }
        }

        return (string) trans('nfse::general.invoices.emit_blocked_invalid_fiscal_profile', [
            'issues' => $issues !== [] ? implode('; ', $issues) : trans('nfse::general.items.validation.status_invalid'),
            'version' => $version,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function itemsMissingNationalTaxCode(Invoice $invoice): array
    {
        $items = $this->invoiceItemsAsArray($invoice);
        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0,
            $items,
        ), static fn (int $itemId): bool => $itemId > 0)));

        $companyId = is_numeric($invoice->company_id ?? null)
            ? (int) $invoice->company_id
            : $this->resolveCompanyId();
        $profileMap = $this->invoiceItemFiscalProfileMap($companyId, $itemIds);
        $defaultNationalCode = $this->nationalTaxCode();

        $missing = [];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;

            if ($itemId <= 0) {
                continue;
            }

            $profile = is_array($profileMap[$itemId] ?? null) ? $profileMap[$itemId] : [];
            $nationalCode = preg_replace(
                '/\\D+/',
                '',
                (string) ($profile['codigo_tributacao_nacional'] ?? $defaultNationalCode),
            ) ?: '';

            if ($nationalCode !== '') {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $serviceCode = Lc116Code::normalize($profile['item_lista_servico'] ?? '');
            $label = $name !== '' ? $name : ('Item #' . $itemId);
            $label .= ' (ID ' . $itemId;

            if ($serviceCode !== '') {
                $label .= ', LC 116 ' . $serviceCode;
            }

            $missing[] = $label . ')';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array{codigo_municipio: string, cep: string, logradouro: string, numero: string, complemento: string, bairro: string, inscricao_municipal: string, telefone: string, email: string}
     */
    protected function tomadorPayload(?object $contact, ?object $invoice = null): array
    {
        return (new InvoiceTakerResolver())->payload($contact, $invoice);
    }

    protected function normalizedTomadorMunicipioIbge(?object $contact, ?object $invoice = null): string
    {
        $raw = $this->contactOrInvoiceStringField(
            $contact,
            $invoice,
            ['municipio_ibge', 'city_ibge', 'ibge_code', 'city_code', 'city'],
            ['contact_municipio_ibge', 'contact_city_ibge', 'contact_ibge_code', 'contact_city_code', 'contact_city']
        );
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        return strlen($digits) === 7 ? $digits : '';
    }

    protected function normalizedTomadorCep(string $cep): string
    {
        $digits = preg_replace('/\D+/', '', $cep) ?: '';

        return strlen($digits) === 8 ? $digits : '';
    }

    protected function normalizedTomadorTelefone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        return strlen($digits) >= 8 && strlen($digits) <= 13 ? $digits : '';
    }

    protected function normalizedTomadorEmail(string $email): string
    {
        $normalized = trim($email);

        if ($normalized === '') {
            return '';
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false ? $normalized : '';
    }

    /**
     * @param list<string> $fields
     */
    protected function contactStringField(?object $contact, array $fields): string
    {
        if ($contact === null) {
            return '';
        }

        foreach ($fields as $field) {
            if (!isset($contact->{$field})) {
                continue;
            }

            return trim((string) $contact->{$field});
        }

        return '';
    }

    /**
     * @param list<string> $contactFields
     * @param list<string> $invoiceFields
     */
    protected function contactOrInvoiceStringField(?object $contact, ?object $invoice, array $contactFields, array $invoiceFields = []): string
    {
        $contactValue = $this->contactStringField($contact, $contactFields);

        if ($contactValue !== '') {
            return $contactValue;
        }

        return $this->invoiceStringField($invoice, $invoiceFields);
    }

    /**
     * @param list<string> $fields
     */
    protected function invoiceStringField(?object $invoice, array $fields): string
    {
        if ($invoice === null) {
            return '';
        }

        foreach ($fields as $field) {
            if (!isset($invoice->{$field})) {
                continue;
            }

            return trim((string) $invoice->{$field});
        }

        return '';
    }

    protected function ensureInvoiceRelationsLoaded(Invoice $invoice): void
    {
        if (method_exists($invoice, 'loadMissing')) {
            try {
                $invoice->loadMissing(['contact', 'items.item_taxes', 'items.taxes']);

                return;
            } catch (\Throwable) {
                $invoice->loadMissing(['contact', 'items']);
            }
        }
    }

    protected function canonicalTaxPolicyMessage(Invoice $invoice): string
    {
        if ($this->invoiceHasNativeItemTaxes($invoice)) {
            return trans('nfse::general.invoices.tax_policy_notice_with_item_taxes');
        }

        return trans('nfse::general.invoices.tax_policy_notice');
    }

    protected function invoiceHasNativeItemTaxes(Invoice $invoice): bool
    {
        $items = $this->invoiceItemsAsArray($invoice);

        foreach ($items as $item) {
            if (is_array($item) && !empty($item['tax_ids'])) {
                return true;
            }

            if (is_array($item) && !empty($item['item_taxes'])) {
                return true;
            }

            if (is_object($item)) {
                if (isset($item->tax_ids) && !empty($item->tax_ids)) {
                    return true;
                }

                if (isset($item->item_taxes) && !empty($item->item_taxes)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function safeLogInfo(string $message, array $context = []): void
    {
        if (!function_exists('logger')) {
            return;
        }

        try {
            logger()->info($message, $context);
        } catch (\Throwable) {
            // Logging must never block NFS-e flows in degraded test/runtime contexts.
        }
    }

    protected function safeLogError(string $message, array $context = []): void
    {
        if (!function_exists('logger')) {
            return;
        }

        try {
            logger()->error($message, $context);
        } catch (\Throwable) {
            // Logging must never block NFS-e flows in degraded test/runtime contexts.
        }
    }

    /**
     * Only a structured, confirmed issuance rejection may reach the operator.
     * Generic HTTP responses and raw gateway payloads are never surfaced.
     */
    protected function safeIssuanceDetail(GatewayException $exception): ?string
    {
        $official = (new OfficialIssuanceRejection())->fromException($exception);

        return $official !== null ? $official['code'] . ' - ' . $official['message'] : null;
    }

    protected function gatewayErrorDetail(GatewayException $exception): ?string
    {
        $payload = $exception->upstreamPayload;

        if (!is_array($payload)) {
            return null;
        }

        $firstError = null;

        if (isset($payload['erros']) && is_array($payload['erros']) && isset($payload['erros'][0]) && is_array($payload['erros'][0])) {
            $firstError = $payload['erros'][0];
        } elseif (isset($payload['erro']) && is_array($payload['erro'])) {
            if (isset($payload['erro'][0]) && is_array($payload['erro'][0])) {
                $firstError = $payload['erro'][0];
            } else {
                $firstError = $payload['erro'];
            }
        } elseif (
            isset($payload['codigo'])
            || isset($payload['Codigo'])
            || isset($payload['descricao'])
            || isset($payload['Descricao'])
            || isset($payload['detail'])
            || isset($payload['Detail'])
            || isset($payload['title'])
            || isset($payload['Title'])
            || isset($payload['errors'])
            || isset($payload['Errors'])
            || isset($payload['message'])
            || isset($payload['Message'])
            || isset($payload['mensagem'])
            || isset($payload['Mensagem'])
            || isset($payload['complemento'])
            || isset($payload['Complemento'])
        ) {
            $firstError = $payload;
        }

        if (!is_array($firstError)) {
            $fallback = trim($exception->getMessage());

            return $fallback !== '' ? $fallback : null;
        }

        $code = trim((string) ($firstError['Codigo'] ?? $firstError['codigo'] ?? ''));
        $description = trim((string) (
            $firstError['Descricao']
            ?? $firstError['descricao']
            ?? $firstError['detail']
            ?? $firstError['Detail']
            ?? $firstError['title']
            ?? $firstError['Title']
            ?? $firstError['mensagem']
            ?? $firstError['Mensagem']
            ?? $firstError['message']
            ?? ''
        ));
        $complement = trim((string) ($firstError['Complemento'] ?? $firstError['complemento'] ?? ''));

        if ($complement === '' && isset($firstError['errors']) && is_array($firstError['errors'])) {
            foreach ($firstError['errors'] as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0]) && trim($messages[0]) !== '') {
                    $complement = trim($messages[0]);

                    break;
                }

                if (is_string($messages) && trim($messages) !== '') {
                    $complement = trim($messages);

                    break;
                }
            }
        }

        $parts = array_filter([$code, $description, $complement], static fn (string $value): bool => $value !== '');

        if ($parts === []) {
            $fallback = trim($exception->getMessage());

            return $fallback !== '' ? $fallback : null;
        }

        return implode(' - ', $parts);
    }

    protected function isCancellationAlreadyRegistered(GatewayException $exception, ?string $gatewayDetail = null): bool
    {
        if ($gatewayDetail !== null && stripos($gatewayDetail, 'E0840') !== false) {
            return true;
        }

        $payload = $exception->upstreamPayload;

        if (!is_array($payload)) {
            return false;
        }

        $errors = [];

        if (isset($payload['erro']) && is_array($payload['erro'])) {
            $errors = isset($payload['erro'][0]) && is_array($payload['erro'][0])
                ? $payload['erro']
                : [$payload['erro']];
        } elseif (isset($payload['erros']) && is_array($payload['erros'])) {
            $errors = $payload['erros'];
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = strtoupper(trim((string) ($error['Codigo'] ?? $error['codigo'] ?? '')));

            if ($code === 'E0840') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{total: int, emitted: int, cancelled: int, sandbox_mode: bool}
     */
    protected function dashboardStats(): array
    {
        return [
            'total' => NfseReceipt::count(),
            'emitted' => NfseReceipt::where('status', 'emitted')->count(),
            'cancelled' => NfseReceipt::where('status', 'cancelled')->count(),
            'sandbox_mode' => $this->sandboxModeEnabled(),
        ];
    }

    protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
    {
        $query = NfseReceipt::with('invoice.contact');

        if (is_object($query) && is_callable([$query, 'whereHas'])) {
            $query = $query->whereHas('invoice', static fn ($invoiceQuery) => $invoiceQuery
                ->where('type', Invoice::INVOICE_TYPE)
                ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE)));
        }

        if ($status !== 'all') {
            if (str_contains($status, ',')) {
                $statuses = array_values(array_filter(array_map(static fn (string $item): string => trim($item), explode(',', $status))));

                if ($statuses !== []) {
                    $query = $query->whereIn('status', $statuses);
                }
            } else {
                $query = $query->where('status', $status);
            }
        }

        if ($search !== null) {
            $query = $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('nfse_number', 'like', '%' . $search . '%')
                    ->orWhere('chave_acesso', 'like', '%' . $search . '%')
                    ->orWhere('codigo_verificacao', 'like', '%' . $search . '%')
                    ->orWhereHas('invoice', function ($invoiceQuery) use ($search) {
                        $invoiceQuery->where('document_number', 'like', '%' . $search . '%')
                            ->orWhereHas('contact', function ($contactQuery) use ($search) {
                                $contactQuery->where('name', 'like', '%' . $search . '%');
                            });
                    });
            });
        }

        if ($dateFilter !== null) {
            $operator = $dateFilter['operator'];
            $from     = $dateFilter['from'];
            $to       = $dateFilter['to'] ?? null;

            if ($operator === 'range' && $to !== null) {
                $query = $query->whereDate('data_emissao', '>=', $from)
                               ->whereDate('data_emissao', '<=', $to);
            } elseif ($operator === '!=') {
                $query = $query->whereDate('data_emissao', '!=', $from);
            } else {
                $query = $query->whereDate('data_emissao', '=', $from);
            }
        }

        $query = $this->applyReceiptsSorting($query);

        return $query->paginate($perPage);
    }

    /**
     * @return array{total: int, emitted: int, processing: int, cancelled: int, pending: int}
     */
    protected function listingOverviewCounts(): array
    {
        try {
            $totalReceiptsQuery = NfseReceipt::query();

            if (is_object($totalReceiptsQuery) && is_callable([$totalReceiptsQuery, 'whereHas'])) {
                $totalReceiptsQuery = $totalReceiptsQuery->whereHas('invoice', static fn ($invoiceQuery) => $invoiceQuery
                    ->where('type', Invoice::INVOICE_TYPE)
                    ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE)));
            }

            $totalReceipts = (is_object($totalReceiptsQuery) && is_callable([$totalReceiptsQuery, 'count']))
                ? $totalReceiptsQuery->count()
                : 0;
        } catch (\Throwable) {
            $totalReceipts = 0;
        }

        try {
            $emittedQuery = NfseReceipt::where('status', 'emitted');

            if (is_object($emittedQuery) && is_callable([$emittedQuery, 'whereHas'])) {
                $emittedQuery = $emittedQuery->whereHas('invoice', static fn ($invoiceQuery) => $invoiceQuery
                    ->where('type', Invoice::INVOICE_TYPE)
                    ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE)));
            }

            $emitted = (is_object($emittedQuery) && is_callable([$emittedQuery, 'count']))
                ? $emittedQuery->count()
                : 0;
        } catch (\Throwable) {
            $emitted = 0;
        }

        try {
            $processingQuery = NfseReceipt::where('status', 'processing');

            if (is_object($processingQuery) && is_callable([$processingQuery, 'whereHas'])) {
                $processingQuery = $processingQuery->whereHas('invoice', static fn ($invoiceQuery) => $invoiceQuery
                    ->where('type', Invoice::INVOICE_TYPE)
                    ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE)));
            }

            $processing = (is_object($processingQuery) && is_callable([$processingQuery, 'count']))
                ? $processingQuery->count()
                : 0;
        } catch (\Throwable) {
            $processing = 0;
        }

        try {
            $cancelledQuery = NfseReceipt::where('status', 'cancelled');

            if (is_object($cancelledQuery) && is_callable([$cancelledQuery, 'whereHas'])) {
                $cancelledQuery = $cancelledQuery->whereHas('invoice', static fn ($invoiceQuery) => $invoiceQuery
                    ->where('type', Invoice::INVOICE_TYPE)
                    ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE)));
            }

            $cancelled = (is_object($cancelledQuery) && is_callable([$cancelledQuery, 'count']))
                ? $cancelledQuery->count()
                : 0;
        } catch (\Throwable) {
            $cancelled = 0;
        }

        $pending = 0;

        try {
            $pendingQuery = $this->pendingInvoicesQuery();

            if (is_object($pendingQuery) && is_callable([$pendingQuery, 'count'])) {
                $pending = (int) $pendingQuery->count();
            }
        } catch (\Throwable) {
            $pending = 0;
        }

        return [
            'total' => $totalReceipts,
            'emitted' => $emitted,
            'processing' => $processing,
            'cancelled' => $cancelled,
            'pending' => $pending,
        ];
    }

    protected function normalizedIndexStatus(mixed $status): string
    {
        if (!is_string($status)) {
            return 'all';
        }

        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return 'all';
        }

        $statuses = $this->selectedIndexStatuses($normalized);

        if ($statuses === [] || in_array('all', $statuses, true)) {
            return 'all';
        }

        return implode(',', $statuses);
    }

    /**
     * @return list<string>
     */
    protected function selectedIndexStatuses(string $status): array
    {
        $allowed = ['all', 'emitted', 'cancelled', 'processing', 'pending'];
        $items = array_map(
            static fn (string $item): string => trim(strtolower($item)),
            explode(',', $status),
        );
        $items = array_values(array_unique(array_filter(
            $items,
            static fn (string $item): bool => $item !== '' && in_array($item, $allowed, true),
        )));

        if (in_array('all', $items, true)) {
            return ['all'];
        }

        return $items;
    }

    protected function receiptStatusForIndex(string $status): ?string
    {
        $selectedStatuses = $this->selectedIndexStatuses($status);

        if ($selectedStatuses === ['all']) {
            return 'all';
        }

        $receiptStatuses = array_values(array_filter(
            $selectedStatuses,
            static fn (string $item): bool => $item !== 'pending',
        ));

        if ($receiptStatuses === []) {
            return null;
        }

        return implode(',', $receiptStatuses);
    }

    protected function normalizedIndexPerPage(mixed $perPage): int
    {
        $allowed = [10, 25, 50, 100];
        $normalized = is_numeric($perPage) ? (int) $perPage : 25;

        return in_array($normalized, $allowed, true) ? $normalized : 25;
    }

    protected function normalizedIndexSearch(mixed $search): ?string
    {
        if (!is_string($search)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($search));

        if (!is_string($normalized)) {
            return null;
        }

        // Akaunting search input can submit free text wrapped in double quotes.
        if (preg_match('/^"(.*)"$/', $normalized, $matches) === 1) {
            $normalized = trim($matches[1]);
        }

        return $normalized !== '' ? $normalized : null;
    }

    protected function normalizedIndexSortBy(mixed $sortBy): string
    {
        if (!is_string($sortBy)) {
            return 'due_at';
        }

        $normalized = strtolower(trim($sortBy));
        $allowed = ['due_at', 'issued_at', 'status', 'document_number', 'customer', 'amount', 'created_at'];

        return in_array($normalized, $allowed, true) ? $normalized : 'due_at';
    }

    protected function normalizedIndexSortDirection(mixed $direction): string
    {
        if (!is_string($direction)) {
            return 'desc';
        }

        $normalized = strtolower(trim($direction));

        return in_array($normalized, ['asc', 'desc'], true) ? $normalized : 'desc';
    }

    protected function applyReceiptsSorting(mixed $query): mixed
    {
        if (!is_object($query)) {
            return $query;
        }

        if (!is_callable([$query, 'orderBy'])) {
            if (is_callable([$query, 'latest'])) {
                return $query->latest();
            }

            return $query;
        }

        $direction = $this->indexSortDirection;

        return match ($this->indexSortBy) {
            'status' => $query->orderBy('status', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'document_number' => $query->orderBy(
                Invoice::query()->select('document_number')->whereColumn('documents.id', 'nfse_receipts.invoice_id')->limit(1),
                $direction,
            ),
            'issued_at' => $query->orderBy(
                Invoice::query()->select('issued_at')->whereColumn('documents.id', 'nfse_receipts.invoice_id')->limit(1),
                $direction,
            ),
            'due_at' => $query->orderBy(
                Invoice::query()->select('due_at')->whereColumn('documents.id', 'nfse_receipts.invoice_id')->limit(1),
                $direction,
            ),
            'customer' => $query->orderBy(
                \App\Models\Common\Contact::query()
                    ->select('name')
                    ->join('documents', 'documents.contact_id', '=', 'contacts.id')
                    ->whereColumn('documents.id', 'nfse_receipts.invoice_id')
                    ->limit(1),
                $direction,
            ),
            'amount' => $query->orderBy(
                Invoice::query()->select('amount')->whereColumn('documents.id', 'nfse_receipts.invoice_id')->limit(1),
                $direction,
            ),
            default => $query->orderBy('data_emissao', $direction),
        };
    }

    /**
     * @param array{status: ?string, per_page: ?int, search: ?string, date_emissao: ?array{operator: string, from: string, to: ?string}} $parsedFilters
     * @return array<string, array<string, mixed>>
     */
    protected function searchStringCookieFilters(array $parsedFilters): array
    {
        $filters = [];

        if (!empty($parsedFilters['status'])) {
            $statusLabels = [
                'all' => trans('nfse::general.invoices.filter_all'),
                'pending' => trans('nfse::general.invoices.filter_pending'),
                'emitted' => trans('nfse::general.invoices.filter_emitted'),
                'processing' => trans('nfse::general.invoices.filter_processing'),
                'cancelled' => trans('nfse::general.invoices.filter_cancelled'),
            ];

            $statuses = array_values(array_filter(array_map(static fn (string $item): string => trim($item), explode(',', (string) $parsedFilters['status']))));

            if (count($statuses) > 1) {
                $multipleValues = [];

                foreach ($statuses as $status) {
                    $multipleValues[] = [
                        'key' => $status,
                        'value' => $statusLabels[$status] ?? $status,
                    ];
                }

                $filters['status'] = [
                    'key' => $multipleValues,
                    'value' => $multipleValues,
                    'operator' => '=',
                ];
            } else {
                $status = $statuses[0] ?? null;

                if ($status !== null) {
                    $filters['status'] = [
                        'key' => $status,
                        'value' => $statusLabels[$status] ?? $status,
                        'operator' => '=',
                    ];
                }
            }
        }

        if (!empty($parsedFilters['date_emissao'])) {
            $dateFilter = $parsedFilters['date_emissao'];
            $operator = $dateFilter['operator'];
            $from = $dateFilter['from'];
            $to = $dateFilter['to'] ?? null;
            try {
                $dateFormat = company_date_format();
            } catch (\Throwable) {
                $dateFormat = 'Y-m-d';
            }

            $key = $from;
            $value = $this->formatDateForSearchFilter($from, $dateFormat);

            if ($operator === 'range' && $to !== null) {
                $key = $from . '-to-' . $to;
                $value = $this->formatDateForSearchFilter($from, $dateFormat)
                    . ' to '
                    . $this->formatDateForSearchFilter($to, $dateFormat);
                $operator = '><';
            }

            $filters['data_emissao'] = [
                'key' => $key,
                'value' => $value,
                'operator' => $operator,
            ];
        }

        return $filters;
    }

    protected function formatDateForSearchFilter(string $date, string $dateFormat): string
    {
        try {
            return (new \DateTimeImmutable($date))->format($dateFormat);
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * @return array{status: ?string, per_page: ?int, search: ?string, date_emissao: ?array{operator: string, from: string, to: ?string}}
     */
    protected function parsedIndexSearchFilters(?string $search): array
    {
        if ($search === null) {
            return ['status' => null, 'per_page' => null, 'search' => null, 'date_emissao' => null];
        }

        $status = null;
        $perPage = null;
        $dateFilter = null;
        $datePattern = '[0-9]{4}-[0-9]{2}-[0-9]{2}';

        if (preg_match('/(?:^|\s)status:([^\s]+)/i', $search, $statusMatch) === 1) {
            $statusTokens = array_values(array_filter(array_map(static fn (string $item): string => trim(strtolower($item)), explode(',', $statusMatch[1]))));
            $allowedStatuses = ['all', 'emitted', 'cancelled', 'processing', 'pending'];
            $validStatuses = array_values(array_unique(array_filter($statusTokens, static fn (string $item): bool => in_array($item, $allowedStatuses, true))));

            if ($validStatuses !== []) {
                $status = in_array('all', $validStatuses, true) ? 'all' : implode(',', $validStatuses);
            }
        }

        if (preg_match('/(?:^|\s)per_page:(10|25|50|100)\b/i', $search, $perPageMatch) === 1) {
            $perPage = $this->normalizedIndexPerPage($perPageMatch[1]);
        }

        // Range: data_emissao>=YYYY-MM-DD data_emissao<=YYYY-MM-DD (order-independent)
        if (preg_match('/(?:^|\s)data_emissao>=(' . $datePattern . ')/i', $search, $fromMatch) === 1
            && preg_match('/(?:^|\s)data_emissao<=(' . $datePattern . ')/i', $search, $toMatch) === 1) {
            $dateFilter = ['operator' => 'range', 'from' => $fromMatch[1], 'to' => $toMatch[1]];
        } elseif (preg_match('/(?:^|\s)not\s+data_emissao:(' . $datePattern . ')(?:\s|$)/i', $search, $notMatch) === 1) {
            // Not equal: not data_emissao:YYYY-MM-DD
            $dateFilter = ['operator' => '!=', 'from' => $notMatch[1], 'to' => null];
        } elseif (preg_match('/(?:^|\s)data_emissao:(' . $datePattern . ')(?:\s|$)/i', $search, $equalMatch) === 1) {
            // Equal: data_emissao:YYYY-MM-DD
            $dateFilter = ['operator' => '=', 'from' => $equalMatch[1], 'to' => null];
        }

        $searchWithoutTokens = preg_replace('/(?:^|\s)(status:[^\s]+|per_page:(?:10|25|50|100))\b/i', ' ', $search);
        $searchWithoutTokens = preg_replace('/(?:^|\s)(?:not\s+data_emissao:[0-9]{4}-[0-9]{2}-[0-9]{2}|data_emissao(?:>=|<=|:)[0-9]{4}-[0-9]{2}-[0-9]{2})/i', ' ', (string) $searchWithoutTokens);
        $searchWithoutTokens = is_string($searchWithoutTokens) ? preg_replace('/\s+/', ' ', trim($searchWithoutTokens)) : null;

        return [
            'status' => $status,
            'per_page' => $perPage,
            'search' => $this->normalizedIndexSearch($searchWithoutTokens),
            'date_emissao' => $dateFilter,
        ];
    }

    protected function pendingInvoices(int $perPage = 25, ?string $search = null): iterable
    {
        $query = $this->pendingInvoicesQuery($search);
        $query = $this->applyPendingInvoicesSorting($query);

        return $query->paginate($perPage);
    }

    protected function applyPendingInvoicesSorting(mixed $query): mixed
    {
        if (!is_object($query)) {
            return $query;
        }

        if (!is_callable([$query, 'orderBy'])) {
            if (is_callable([$query, 'latest'])) {
                return $query->latest();
            }

            return $query;
        }

        $direction = $this->indexSortDirection;

        if ($this->indexSortBy === 'customer' && is_callable([$query, 'leftJoin']) && is_callable([$query, 'select'])) {
            $query = $query->leftJoin('contacts', 'contacts.id', '=', 'documents.contact_id')
                ->select('documents.*')
                ->orderBy('contacts.name', $direction);

            return $query;
        }

        return match ($this->indexSortBy) {
            'document_number' => $query->orderBy('document_number', $direction),
            'amount' => $query->orderBy('amount', $direction),
            'issued_at' => $query->orderBy('issued_at', $direction)->orderBy('created_at', $direction),
            'due_at' => $query->orderBy('due_at', $direction)->orderBy('created_at', $direction),
            default => $query->orderBy('created_at', $direction),
        };
    }

    protected function requestHasIndexState(?Request $request): bool
    {
        if (!$request instanceof Request) {
            return false;
        }

        $keys = ['search', 'q', 'status', 'limit', 'per_page', 'sort', 'direction', 'sort_by', 'sort_direction'];

        foreach ($keys as $key) {
            if ($request->query($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{status: ?string, per_page: int, search: ?string, sort_by: string, sort_direction: string} $preferences
     * @return array<string, string|int>
     */
    protected function indexRestoreQueryParams(array $preferences): array
    {
        return array_filter([
            'status' => (string) ($preferences['status'] ?? 'all'),
            'limit' => (int) ($preferences['per_page'] ?? 25),
            'search' => (string) ($preferences['search'] ?? ''),
            'sort' => (string) ($preferences['sort_by'] ?? 'due_at'),
            'direction' => (string) ($preferences['sort_direction'] ?? 'desc'),
        ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    /**
     * @return array{status: ?string, per_page: int, search: ?string, sort_by: string, sort_direction: string}|array{}
     */
    protected function loadIndexPreferences(): array
    {
        $raw = setting($this->indexPreferencesSettingKey(), null);
        $decoded = null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        if (!is_array($decoded)) {
            return [];
        }

        return [
            'status' => isset($decoded['status']) ? $this->normalizedIndexStatus($decoded['status']) : null,
            'per_page' => $this->normalizedIndexPerPage($decoded['per_page'] ?? null),
            'search' => $this->normalizedIndexSearch($decoded['search'] ?? null),
            'sort_by' => $this->normalizedIndexSortBy($decoded['sort_by'] ?? null),
            'sort_direction' => $this->normalizedIndexSortDirection($decoded['sort_direction'] ?? null),
        ];
    }

    /**
     * @param array{status: string, per_page: int, search: ?string, sort_by: string, sort_direction: string} $preferences
     */
    protected function saveIndexPreferences(array $preferences): void
    {
        setting([$this->indexPreferencesSettingKey() => json_encode($preferences)]);

        $settings = setting();

        if (is_object($settings) && is_callable([$settings, 'save'])) {
            $settings->save();
        }
    }

    protected function canRestoreIndexPreferences(array $preferences): bool
    {
        // Restore saved non-default listing state only on bare URL requests.
        // Explicit request parameters (including ?search= from clear action) are
        // handled before this method and always take precedence.
        $status = $preferences['status'] ?? null;
        $search = $preferences['search'] ?? null;
        $perPage = (int) ($preferences['per_page'] ?? 25);
        $sortBy = (string) ($preferences['sort_by'] ?? 'due_at');
        $sortDirection = (string) ($preferences['sort_direction'] ?? 'desc');

        $hasNonDefaultStatus = $status !== null && $status !== 'all';
        $hasSearch = is_string($search) && trim($search) !== '';
        $hasNeutralOverrides = $perPage !== 25 || $sortBy !== 'due_at' || $sortDirection !== 'desc';

        return $hasNonDefaultStatus || $hasSearch || $hasNeutralOverrides;
    }

    protected function indexPreferencesSettingKey(): string
    {
        $key = 'nfse.invoices.preferences';

        if (!function_exists('user')) {
            return $key;
        }

        try {
            $currentUser = user();

            if (is_object($currentUser)) {
                $userId = (int) ($currentUser->id ?? 0);

                if ($userId > 0) {
                    return $key . '.' . $userId;
                }
            }
        } catch (\Throwable) {
            return $key;
        }

        return $key;
    }

    protected function pendingInvoicesQuery(?string $search = null): mixed
    {
        $receiptTable = (new NfseReceipt())->getTable();

        $query = Invoice::invoice()
            ->with(['contact'])
            ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE))
            ->whereHas('items')
            ->whereNotExists(static function ($subQuery) use ($receiptTable): void {
                $subQuery->selectRaw('1')
                    ->from($receiptTable)
                    ->whereColumn($receiptTable . '.invoice_id', 'documents.id');
            });

        if ($search !== null) {
            $query = $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('document_number', 'like', '%' . $search . '%')
                    ->orWhere('amount', 'like', '%' . $search . '%')
                    ->orWhereHas('contact', function ($contactQuery) use ($search) {
                        $contactQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        return $query;
    }

    /**
     * @return array{checklist: array<string, bool>, isReady: bool}
     */
    protected function emissionReadiness(): array
    {
        $settings = setting('nfse', []);

        $settingsArray = is_array($settings) ? $settings : [];
        $cnpj = trim((string) ($settingsArray['cnpj_prestador'] ?? ''));
        $certificatePath = $cnpj !== ''
            ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx')
            : '';

        return (new OperationalReadinessResolver())->evaluate(
            settings: $settingsArray,
            hasCertificateSecret: $this->hasCertificateSecret($cnpj),
            certificatePath: $certificatePath,
        );
    }

    protected function itemListaServico(?object $defaultService = null): string
    {
        $serviceCode = Lc116Code::normalize($defaultService->item_lista_servico ?? '');

        if ($serviceCode !== '') {
            return $serviceCode;
        }

        return Lc116Code::normalize(setting('nfse.item_lista_servico', ''));
    }

    protected function nationalTaxCode(?object $defaultService = null): string
    {
        $configured = preg_replace('/\D+/', '', (string) ($defaultService->codigo_tributacao_nacional ?? '')) ?: '';

        if ($configured === '') {
            $configured = preg_replace('/\D+/', '', (string) setting('nfse.codigo_tributacao_nacional', '')) ?: '';
        }

        if ($configured !== '') {
            return str_pad(substr($configured, 0, 6), 6, '0', STR_PAD_LEFT);
        }

        return '';
    }

    protected function normalizedAliquota(?object $defaultService = null): string
    {
        $configured = (string) ($defaultService->aliquota ?? '');

        if ($configured === '') {
            $configured = (string) setting('nfse.aliquota', '5.00');
        }

        $normalized = str_replace(',', '.', trim($configured));

        return number_format((float) $normalized, 2, '.', '');
    }

    protected function recoverReceiptAfterAmbiguousEmission(NfseClientInterface $client, DpsData $dps): ?ReceiptData
    {
        $recovery = new RecoverInvoiceEmission();

        try {
            return $recovery->recover($client, $dps);
        } catch (\Throwable $recoveryError) {
            $this->safeLogError('NFS-e DPS recovery failed after ambiguous emission', [
                'message' => $recoveryError->getMessage(),
                'dps_id' => $recovery->dpsIdentifier($dps),
            ]);

            return null;
        }
    }

    protected function dpsSerie(Invoice $invoice): string
    {
        return (new InvoiceDpsIdentity())->series($invoice);
    }

    protected function dpsNumber(Invoice $invoice): string
    {
        return (new InvoiceDpsIdentity())->number($invoice);
    }

    protected function dpsNumberForReemit(Invoice $invoice): string
    {
        // Stable across a crashed/ambiguous retry. A new cancellation has its
        // own persisted receipt id, so distinct reemission operations differ.
        $cancelledReceipt = $this->findReceiptForInvoice($invoice);
        $receiptId = (int) $cancelledReceipt->id;

        if ($receiptId <= 0 || strlen((string) $receiptId) > 14) {
            throw new \LogicException('Cancelled receipt cannot be mapped to a stable reemission DPS.');
        }

        return '9' . str_pad((string) $receiptId, 14, '0', STR_PAD_LEFT);
    }

    protected function competenceDate(Invoice $invoice): ?string
    {
        return (new InvoiceDpsIdentity())->competenceDate($invoice);
    }

    protected function resolveCompanyId(): int
    {
        if (function_exists('company_id')) {
            try {
                $companyId = (int) (company_id() ?? 0);
            } catch (\Throwable) {
                $companyId = 0;
            }

            if ($companyId > 0) {
                return $companyId;
            }
        }

        if (! function_exists('auth')) {
            return 0;
        }

        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return 0;
        }

        return $user !== null && isset($user->company_id) ? (int) $user->company_id : 0;
    }

    protected function normalizedOpcaoSimplesNacional(): int
    {
        $configured = (int) setting('nfse.opcao_simples_nacional', 2);

        return in_array($configured, [1, 2, 3], true) ? $configured : 1;
    }

    /**
     * @return array{
     *   enabled: bool,
     *   ibsCbsFinalidade: ?int,
     *   ibsCbsIndFinal: ?int,
     *   ibsCbsCodigoIndicadorOperacao: string,
     *   ibsCbsIndDest: ?int,
     *   ibsCbsCst: string,
     *   ibsCbsClassificacaoTributaria: string
     * }
     */
    /**
     * @return array{
     *   tributacaoIssqn: int,
     *   issqnPaisResultado: string,
     *   issqnTipoImunidade: ?int,
     *   issqnTipoSuspensao: ?int,
     *   issqnNumeroProcessoSuspensao: string,
     *   tipoRetencaoIss: int,
     *   requiresSpecialRuntime: bool
     * }
     */
    protected function issqnPayloadValues(): array
    {
        return (new IssqnPayloadResolver())->resolve([
            'tributacao_issqn' => setting('nfse.tributacao_issqn', 1),
            'tipo_retencao_iss' => setting('nfse.tipo_retencao_iss', 1),
            'issqn_pais_resultado' => setting('nfse.issqn_pais_resultado', ''),
            'issqn_tipo_imunidade' => setting('nfse.issqn_tipo_imunidade', ''),
            'issqn_tipo_suspensao' => setting('nfse.issqn_tipo_suspensao', ''),
            'issqn_numero_processo_suspensao' => setting('nfse.issqn_numero_processo_suspensao', ''),
        ]);
    }

    /**
     * @param array{effective_date?:?string,reason?:string,missing?:list<string>} $readiness
     */
    protected function ibsCbsBlockedMessage(array $readiness): string
    {
        $missing = is_array($readiness['missing'] ?? null)
            ? array_values(array_map('strval', $readiness['missing']))
            : [];

        $key = match ($readiness['reason'] ?? '') {
            'unverifiable' => 'nfse::general.invoices.emit_blocked_ibs_cbs_unverifiable',
            'invalid_configuration' => 'nfse::general.invoices.emit_blocked_ibs_cbs_invalid',
            default => 'nfse::general.invoices.emit_blocked_ibs_cbs_required',
        };

        return (string) trans($key, [
            'date' => (string) ($readiness['effective_date'] ?? ''),
            'fields' => implode(', ', array_map(
                static fn (string $field): string => (string) trans(
                    'nfse::general.invoices.ibs_cbs_missing_labels.' . $field,
                ),
                $missing,
            )),
        ]);
    }

    protected function ibsCbsPayloadValues(): array
    {
        return (new IbsCbsPayloadResolver())->resolve([
            'enabled' => setting('nfse.ibs_cbs_enabled', false),
            'ind_final' => setting('nfse.ibs_cbs_ind_final', ''),
            'ind_dest' => setting('nfse.ibs_cbs_ind_dest', ''),
            'c_ind_op' => setting('nfse.ibs_cbs_c_ind_op', ''),
            'cst' => setting('nfse.ibs_cbs_cst', ''),
            'c_class_trib' => setting('nfse.ibs_cbs_c_class_trib', ''),
        ]);
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return array<string,mixed>
     */
    protected function federalPayloadValues(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
        mixed $municipalPercentFallback = null,
    ): array {
        return $this->invoiceFederalPayloadResolver()->resolve(
            $invoice,
            $documentItemIds,
            $amountOverride,
            $municipalPercentFallback,
        );
    }

    /** @return array{telefone:string,email:string} */
    protected function providerContact(): array
    {
        try {
            $company = function_exists('company') ? \company() : null;
        } catch (\Throwable) {
            $company = null;
        }

        $phone = is_object($company) ? trim((string) ($company->phone ?? '')) : '';
        $email = is_object($company) ? trim((string) ($company->email ?? '')) : '';

        return [
            'telefone' => preg_replace('/\D+/', '', $phone) ?: '',
            'email' => $email,
        ];
    }

    /**
     * @param list<int>|null $documentItemIds
     */
    protected function invoiceServiceAmount(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): float {
        return $this->invoiceFederalPayloadResolver()->serviceAmount(
            $invoice,
            $documentItemIds,
            $amountOverride,
        );
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    protected function invoiceFederalTaxSnapshot(
        Invoice $invoice,
        float $invoiceAmount,
        ?array $documentItemIds = null,
    ): array {
        return $this->invoiceFederalPayloadResolver()->snapshot(
            $invoice,
            $invoiceAmount,
            $documentItemIds,
        );
    }

    protected function invoiceFederalPayloadResolver(): InvoiceFederalPayloadResolver
    {
        return new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => setting($key, $default),
        );
    }

    protected function resolveFederalTaxRate(mixed $tax, array &$taxRateById): ?float
    {
        $inlineRate = is_array($tax)
            ? ($tax['rate'] ?? null)
            : ($tax->rate ?? null);

        if (is_numeric($inlineRate)) {
            $rate = (float) $inlineRate;

            return $rate > 0 ? $rate : null;
        }

        $taxIdRaw = is_array($tax)
            ? ($tax['tax_id'] ?? null)
            : ($tax->tax_id ?? null);

        if (!is_numeric($taxIdRaw)) {
            return null;
        }

        $taxId = (int) $taxIdRaw;

        if ($taxId <= 0) {
            return null;
        }

        if (array_key_exists($taxId, $taxRateById)) {
            return $taxRateById[$taxId];
        }

        $resolvedRate = null;

        try {
            $rateValue = DB::table('taxes')->where('id', $taxId)->value('rate');

            if (is_numeric($rateValue)) {
                $rateFloat = (float) $rateValue;
                $resolvedRate = $rateFloat > 0 ? $rateFloat : null;
            }
        } catch (\Throwable) {
            $resolvedRate = null;
        }

        $taxRateById[$taxId] = $resolvedRate;

        return $resolvedRate;
    }

    protected function hasNonZeroDecimalValue(string $value): bool
    {
        return $value !== '' && (float) $value !== 0.0;
    }

    private function finalizeFederalPayload(array $payload): array
    {
        $hasConfiguredTotalTributos = $payload['totalTributosPercentualFederal'] !== ''
            || $payload['totalTributosPercentualEstadual'] !== ''
            || $payload['totalTributosPercentualMunicipal'] !== '';

        if ($hasConfiguredTotalTributos || !$this->hasFederalTaxationPayload($payload)) {
            return $payload;
        }

        $payload['indicadorTributacao'] = 2;
        $payload['totalTributosPercentualFederal'] = '0.00';
        $payload['totalTributosPercentualEstadual'] = '0.00';
        $payload['totalTributosPercentualMunicipal'] = '0.00';

        return $payload;
    }

    private function hasFederalTaxationPayload(array $payload): bool
    {
        return $payload['federalPiscofinsSituacaoTributaria'] !== ''
            || $payload['federalPiscofinsTipoRetencao'] !== ''
            || $payload['federalPiscofinsBaseCalculo'] !== ''
            || $payload['federalPiscofinsAliquotaPis'] !== ''
            || $payload['federalPiscofinsValorPis'] !== ''
            || $payload['federalPiscofinsAliquotaCofins'] !== ''
            || $payload['federalPiscofinsValorCofins'] !== ''
            || $this->hasNonZeroDecimalValue($payload['federalValorIrrf'])
            || $this->hasNonZeroDecimalValue($payload['federalValorCsll'])
            || $this->hasNonZeroDecimalValue($payload['federalValorCp']);
    }

    /**
     * Calculate federal retention value in reais based on percentage setting
     */
    protected function calculateFederalRetentionValue(float $invoiceAmount, string $settingKey): string
    {
        $percentageStr = $this->normalizedFederalDecimal(setting('nfse.' . $settingKey, ''));

        if ($percentageStr === '') {
            return '';
        }

        $percentage = (float) $percentageStr;
        if ($percentage <= 0) {
            return '';
        }

        $calculatedValue = $invoiceAmount * $percentage / 100;

        return number_format($calculatedValue, 2, '.', '');
    }

    protected function normalizedFederalSelectValue(mixed $value): string
    {
        $normalized = trim((string) $value);

        return preg_match('/^\d+$/', $normalized) === 1 ? $normalized : '';
    }

    protected function normalizedFederalDecimal(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return '';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function dpsXmlOrderDebug(DpsData $dps): array
    {
        try {
            $xml = (new XmlBuilder())->buildDps($dps);
            $normalized = str_replace(["\r", "\n", "\t"], '', $xml);
            $tpAmbIndex = strpos($normalized, '<tpAmb>');
            $cMunIndex = strpos($normalized, '<cMun>');

            return [
                'xml_builder_file' => (new \ReflectionClass(XmlBuilder::class))->getFileName(),
                'tpAmb_index' => $tpAmbIndex,
                'cMun_index' => $cMunIndex,
                'tpAmb_before_cMun' => $tpAmbIndex !== false && $cMunIndex !== false && $tpAmbIndex < $cMunIndex,
                'xml_prefix' => substr($normalized, 0, 260),
            ];
        } catch (\Throwable $throwable) {
            return [
                'debug_error' => $throwable->getMessage(),
            ];
        }
    }

    protected function hasCertificateSecret(string $cnpj): bool
    {
        if ($cnpj === '') {
            return false;
        }

        try {
            $secret = $this->makeSecretStore()->get('pfx/' . $cnpj);

            return (($secret['password'] ?? '') !== '') && (($secret['pfx_path'] ?? '') !== '');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function makeClient(bool $sandboxMode): NfseClientInterface
    {
        $this->cleanupClientTransportArtifacts();

        try {
            $this->clientContext = $this->makeFiscalClientFactory()->nfse($sandboxMode);
        } catch (SecretStoreException|PfxImportException $exception) {
            $this->safeLogError('NFS-e transport certificate preparation failed', [
                'cnpj' => (string) setting('nfse.cnpj_prestador', ''),
                'sandbox_mode' => $sandboxMode,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $this->clientContext->nfseClient();
    }

    protected function makeFiscalClientFactory(): FiscalClientFactory
    {
        return app(FiscalClientFactory::class);
    }

    protected function cleanupClientTransportArtifacts(): void
    {
        if ($this->clientContext === null) {
            return;
        }

        $this->clientContext->close();
        $this->clientContext = null;
    }

    protected function existingProjectRootPath(string $relativePath): ?string
    {
        $absolutePath = $this->projectRootPath($relativePath);

        return is_file($absolutePath) ? $absolutePath : null;
    }

    protected function projectRootPath(string $relativePath): string
    {
        if (class_exists(\Modules\Nfse\Http\Controllers\ControllerIsolationState::class, false)) {
            try {
                $isolationRoot = \Modules\Nfse\Http\Controllers\ControllerIsolationState::$storageRoot ?? '';

                if (is_string($isolationRoot) && $isolationRoot !== '') {
                    return rtrim($isolationRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
                }
            } catch (\Throwable) {
                // Ignore isolation fallback errors and continue with normal resolution.
            }
        }

        if (function_exists('app')) {
            try {
                $application = app();

                if (is_object($application) && method_exists($application, 'basePath')) {
                    return $application->basePath($relativePath);
                }
            } catch (\Throwable) {
                // Fall back to module-relative path when container helper is unavailable.
            }
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    protected function storeEmittedReceipt(Invoice $invoice, ReceiptData $receipt, ?NfseReceipt $existingReceipt = null): NfseReceipt
    {
        return (new ReceiptPersistence())->storeCurrent(
            invoiceId: (int) $invoice->id,
            receipt: $receipt,
            resolvedNumber: $this->resolveReceiptNfseNumber($receipt),
            existingReceipt: $existingReceipt,
        );
    }

    protected function generateMissingDanfseArtifact(Invoice $invoice, NfseReceipt $receipt, ?array $xmlArtifact): bool
    {
        if (!$this->webDavEnabled() || !$this->webDavStorePdfEnabled()) {
            return false;
        }

        $xmlPath = is_array($xmlArtifact) && is_string($xmlArtifact['path'] ?? null)
            ? trim((string) $xmlArtifact['path'])
            : '';

        if ($xmlPath === '' || !($xmlArtifact['exists'] ?? false)) {
            return false;
        }

        try {
            $webDavClient = $this->makeWebDavClientFromSettings();
            $xml = trim($webDavClient->get($xmlPath));

            if ($xml === '') {
                return false;
            }

            $pdf = (new DanfseGenerator())->generateFromXml($xml);

            if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
                throw new \RuntimeException('Generated DANFSE payload is not a PDF.');
            }

            $receiptData = $this->receiptDataFromModel($receipt);
            $basePath = $this->buildWebDavArtifactBasePath($invoice, $receiptData);
            $danfsePath = $this->buildWebDavArtifactFilePath($basePath, $invoice, $receiptData, 'pdf');

            $webDavClient->put($danfsePath, $pdf);
            $receipt->update(['danfse_webdav_path' => $danfsePath]);

            return true;
        } catch (\Throwable $throwable) {
            $this->safeLogError('NFS-e DANFSE on-demand generation failed', [
                'invoice_id' => $invoice->id,
                'nfse_number' => (string) ($receipt->nfse_number ?? ''),
                'message' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{
     *   danfse: array{path: ?string, exists: bool, source: ?string, download_url: ?string},
     *   xml: array{path: ?string, exists: bool, source: ?string, download_url: ?string}
     * }
     */
    protected function resolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
    {
        $authorizedXml = $this->authorizedXmlForReceipt($receipt);

        if ($authorizedXml !== '') {
            $downloadUrl = static function (string $artifact) use ($invoice): ?string {
                if (!function_exists('route')) {
                    return null;
                }

                try {
                    return route('nfse.invoices.artifacts.download', [
                        'company_id' => $invoice->company_id,
                        'invoice' => $invoice->id,
                        'artifact' => $artifact,
                    ]);
                } catch (\Throwable) {
                    return null;
                }
            };

            return [
                'danfse' => [
                    'path' => trim((string) ($receipt->danfse_webdav_path ?? '')) ?: null,
                    'exists' => true,
                    'source' => trim((string) ($receipt->danfse_webdav_path ?? '')) !== '' ? 'persisted' : 'authorized_xml',
                    'download_url' => $downloadUrl('danfse'),
                ],
                'xml' => [
                    'path' => trim((string) ($receipt->xml_webdav_path ?? '')) ?: null,
                    'exists' => true,
                    'source' => trim((string) ($receipt->xml_webdav_path ?? '')) !== '' ? 'persisted' : 'authorized_xml',
                    'download_url' => $downloadUrl('xml'),
                ],
            ];
        }

        $postEmission = (new PostEmissionState())->snapshot($receipt);

        if (($postEmission['poll'] ?? false) === true) {
            return $this->persistedReceiptArtifacts($invoice, $receipt);
        }

        $receiptData = $this->receiptDataFromModel($receipt);
        $basePath = $this->buildWebDavArtifactBasePath($invoice, $receiptData);

        return [
            'danfse' => $this->resolveSingleReceiptArtifact($invoice, $receipt, $receiptData, $basePath, 'danfse', 'danfse_webdav_path', 'pdf'),
            'xml' => $this->resolveSingleReceiptArtifact($invoice, $receipt, $receiptData, $basePath, 'xml', 'xml_webdav_path', 'xml'),
        ];
    }

    /**
     * @return array{
     *   danfse: array{path: ?string, exists: bool, source: ?string, download_url: ?string},
     *   xml: array{path: ?string, exists: bool, source: ?string, download_url: ?string}
     * }
     */
    protected function persistedReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
    {
        $build = static function (mixed $rawPath, string $artifact) use ($invoice): array {
            $path = is_string($rawPath) ? trim($rawPath) : '';
            $ready = $path !== '';

            return [
                'path' => $ready ? $path : null,
                'exists' => $ready,
                'source' => $ready ? 'persisted' : null,
                'download_url' => $ready
                    ? route('nfse.invoices.artifacts.download', [$invoice->id, $artifact])
                    : null,
            ];
        };

        return [
            'danfse' => $build($receipt->danfse_webdav_path ?? null, 'danfse'),
            'xml' => $build($receipt->xml_webdav_path ?? null, 'xml'),
        ];
    }

    /**
     * @return array{path: ?string, exists: bool, source: ?string, download_url: ?string}
     */
    protected function resolveSingleReceiptArtifact(
        Invoice $invoice,
        NfseReceipt $receipt,
        ReceiptData $receiptData,
        string $basePath,
        string $artifact,
        string $pathField,
        string $extension,
    ): array {
        $path = trim((string) ($receipt->{$pathField} ?? ''));
        $source = $path !== '' ? 'persisted' : null;

        if ($path === '' && $this->webDavEnabled() && $this->artifactStorageEnabledByExtension($extension)) {
            $candidate = $this->buildWebDavArtifactFilePath($basePath, $invoice, $receiptData, $extension);
            $candidate = trim($candidate);

            if ($candidate !== '') {
                $path = $candidate;
                $source = 'template';
            }
        }

        if ($path === '') {
            return [
                'path' => null,
                'exists' => false,
                'source' => null,
                'download_url' => null,
            ];
        }

        $exists = $source === 'persisted'
            ? true
            : ($this->webDavEnabled() ? $this->webDavPathExists($path) : false);

        $downloadUrl = null;

        if ($exists && function_exists('route')) {
            try {
                $downloadUrl = route('nfse.invoices.artifacts.download', ['invoice' => $invoice->id, 'artifact' => $artifact]);
            } catch (\Throwable) {
                $downloadUrl = null;
            }
        }

        return [
            'path' => $path,
            'exists' => $exists,
            'source' => $source,
            'download_url' => $downloadUrl,
        ];
    }

    protected function receiptDataFromModel(NfseReceipt $receipt): ReceiptData
    {
        $issueDate = $receipt->data_emissao ?? null;
        $issueDateString = '';

        if ($issueDate instanceof \DateTimeInterface) {
            $issueDateString = $issueDate->format(DATE_ATOM);
        } elseif (is_string($issueDate)) {
            $issueDateString = trim($issueDate);
        }

        return new ReceiptData(
            nfseNumber: (string) ($receipt->nfse_number ?? ''),
            chaveAcesso: (string) ($receipt->chave_acesso ?? ''),
            dataEmissao: $issueDateString,
            codigoVerificacao: (string) ($receipt->codigo_verificacao ?? ''),
            rawXml: null,
        );
    }

    protected function artifactStorageEnabledByExtension(string $extension): bool
    {
        return $extension === 'xml'
            ? $this->webDavStoreXmlEnabled()
            : $this->webDavStorePdfEnabled();
    }

    protected function webDavPathExists(string $path): bool
    {
        try {
            return $this->makeWebDavClientFromSettings()->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function webDavEnabled(): bool
    {
        return trim((string) setting('nfse.webdav_url', '')) !== '';
    }

    protected function sandboxModeEnabled(): bool
    {
        return $this->booleanSetting('nfse.sandbox_mode', true);
    }

    protected function booleanSetting(string $key, bool $default): bool
    {
        $rawValue = setting($key, null);

        if ($rawValue === null) {
            return $default;
        }

        if (is_bool($rawValue)) {
            return $rawValue;
        }

        if (is_numeric($rawValue)) {
            return (int) $rawValue === 1;
        }

        if (is_string($rawValue)) {
            $normalized = strtolower(trim($rawValue));

            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return (bool) $rawValue;
    }

    protected function webDavStoreXmlEnabled(): bool
    {
        return (bool) setting('nfse.webdav_store_xml', true);
    }

    protected function webDavStorePdfEnabled(): bool
    {
        return (bool) setting('nfse.webdav_store_pdf', true);
    }

    protected function makeWebDavClientFromSettings(): WebDavClient
    {
        return new WebDavClient(
            baseUrl: (string) setting('nfse.webdav_url', ''),
            username: (string) setting('nfse.webdav_username', ''),
            password: (string) setting('nfse.webdav_password', ''),
        );
    }

    protected function buildWebDavArtifactBasePath(Invoice $invoice, ReceiptData $receipt): string
    {
        return (new ArtifactPathBuilder())->basePath(
            template: (string) setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
        );
    }

    protected function buildWebDavArtifactFilePath(string $basePath, Invoice $invoice, ReceiptData $receipt, string $extension): string
    {
        return (new ArtifactPathBuilder())->filePath(
            basePath: $basePath,
            template: (string) setting('nfse.webdav_filename_template', '{chave_acesso}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
            extension: $extension,
        );
    }

    protected function resolveReceiptNfseNumber(ReceiptData $receipt): string
    {
        return (new ReceiptNumberResolver())->resolve($receipt);
    }

    protected function findReceiptForInvoice(Invoice $invoice): NfseReceipt
    {
        $query = NfseReceipt::where('invoice_id', $invoice->id);

        if (is_object($query) && method_exists($query, 'latest')) {
            return $query->latest('id')->firstOrFail();
        }

        if (is_object($query) && method_exists($query, 'orderByDesc')) {
            return $query->orderByDesc('id')->firstOrFail();
        }

        return $query->firstOrFail();
    }

    protected function refreshableReceipts(): iterable
    {
        return NfseReceipt::query()
            ->whereNotNull('chave_acesso')
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->take(30)
            ->get();
    }

    protected function makeSecretStore(): OpenBaoSecretStore
    {
        $config = VaultConfig::secretStoreConfig();

        return new OpenBaoSecretStore(
            addr: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }

    protected function servicePreviewEmailDefaults(Invoice $invoice): array
    {
        $sendEmail = (bool) (int) setting('nfse.send_email_on_emit', '0');
        $recipient = $this->defaultPostEmitRecipient($invoice) ?? '';
        $template  = null;
        $copyToSelf = (bool) (int) setting('nfse.email_copy_to_self_on_emit', '0');
        $attachInvoicePdf = (bool) (int) setting('nfse.email_attach_invoice_pdf_on_emit', '1');
        $attachDanfse = (bool) (int) setting('nfse.email_attach_danfse_on_emit', '1');
        $attachXml = (bool) (int) setting('nfse.email_attach_xml_on_emit', '1');

        try {
            $template = \App\Models\Setting\EmailTemplate::alias('invoice_nfse_issued_customer')->first();
        } catch (\Throwable) {
            // class not available in unit-test context
        }

        $moduleDefaults = \Modules\Nfse\Listeners\FinishInstallation::defaultEmailTemplateContent();

        return [
            'send_email'         => $sendEmail,
            'recipient'          => $recipient,
            'subject'            => $template !== null ? (string) ($template->subject ?? '') : '',
            'body'               => $template !== null ? (string) ($template->body ?? '') : '',
            'default_subject'    => $moduleDefaults['subject'],
            'default_body'       => $moduleDefaults['body'],
            'copy_to_self'       => $copyToSelf,
            'attach_invoice_pdf' => $attachInvoicePdf,
            'attach_danfse'      => $attachDanfse,
            'attach_xml'         => $attachXml,
        ];
    }
    /**
     * Persists the post-emission email preferences and captures an immutable
     * payload for queued delivery. Returning null means no email was requested.
     *
     * @return array{
     *   attach_danfse:bool,
     *   attach_xml:bool,
     *   custom_mail:array<string, mixed>
     * }|null
     */
    protected function preparePostEmitEmail(?Request $request, Invoice $invoice): ?array
    {
        if ($request === null) {
            return null;
        }

        $sendEmail = $request->boolean('nfse_send_email', false);
        $attachInvoicePdf = $request->boolean('nfse_email_attach_invoice_pdf', true);
        $attachDanfse = $request->boolean('nfse_email_attach_danfse', true);
        $attachXml = $request->boolean('nfse_email_attach_xml', true);
        $copyToSelf = $request->boolean('nfse_email_copy_to_self', false);
        $saveDefault = $request->boolean('nfse_email_save_default', false);

        setting([
            'nfse.send_email_on_emit'               => $sendEmail ? '1' : '0',
            'nfse.email_copy_to_self_on_emit'       => $copyToSelf ? '1' : '0',
            'nfse.email_attach_invoice_pdf_on_emit' => $attachInvoicePdf ? '1' : '0',
            'nfse.email_attach_danfse_on_emit'      => $attachDanfse ? '1' : '0',
            'nfse.email_attach_xml_on_emit'         => $attachXml ? '1' : '0',
        ]);
        setting()->save();

        if (!$sendEmail) {
            return null;
        }

        if ($saveDefault) {
            $template = \App\Models\Setting\EmailTemplate::alias('invoice_nfse_issued_customer')->first();

            if ($template) {
                $subject = (string) $request->input('nfse_email_subject', '');
                $body = (string) $request->input('nfse_email_body', '');

                if ($subject !== '') {
                    $template->subject = $subject;
                }

                if ($body !== '') {
                    $template->body = $body;
                }

                $template->save();
            }
        }

        $recipient = $this->normalizePostEmitRecipient($request->input('nfse_email_to'));

        if ($recipient === null) {
            $recipient = $this->defaultPostEmitRecipient($invoice);
        }

        if ($recipient === null) {
            return null;
        }

        $customMail = [
            'to' => $recipient,
            'subject' => (string) $request->input('nfse_email_subject', ''),
            'body' => (string) $request->input('nfse_email_body', ''),
            'attach_invoice_pdf' => $attachInvoicePdf,
        ];

        if ($copyToSelf && function_exists('user')) {
            $selfEmail = (string) (user()?->email ?? '');

            if ($selfEmail !== '') {
                $customMail['bcc'] = $selfEmail;
            }
        }

        return [
            'attach_danfse' => $attachDanfse,
            'attach_xml' => $attachXml,
            'custom_mail' => $customMail,
        ];
    }

    /**
     * @param array{
     *   attach_danfse:bool,
     *   attach_xml:bool,
     *   custom_mail:array<string, mixed>
     * }|null $email
     */
    protected function dispatchPostEmission(
        Invoice $invoice,
        NfseReceipt $receipt,
        ?array $email,
    ): void {
        if (!class_exists(\Illuminate\Support\Facades\Bus::class)) {
            return;
        }

        try {
            (new PostEmissionDispatcher())->dispatch(
                invoiceId: (int) $invoice->id,
                receiptId: (int) $receipt->id,
                email: $email,
            );
        } catch (\Throwable $throwable) {
            // The fiscal document is already authorized at this point. Queue
            // infrastructure failures must never make the user retry issuance.
            $this->safeLogError('NFS-e post-emission dispatch failed', [
                'invoice_id' => (int) $invoice->id,
                'receipt_id' => (int) $receipt->id,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    protected function normalizePostEmitRecipient(mixed $rawRecipient): mixed
    {
        if (is_string($rawRecipient)) {
            $normalized = trim($rawRecipient);

            return $normalized !== '' ? $normalized : null;
        }

        if (is_object($rawRecipient) && isset($rawRecipient->email)) {
            $normalized = trim((string) $rawRecipient->email);

            return $normalized !== '' ? $rawRecipient : null;
        }

        if (is_array($rawRecipient) && isset($rawRecipient['email']) && is_string($rawRecipient['email'])) {
            $normalized = trim($rawRecipient['email']);

            return $normalized !== '' ? $rawRecipient : null;
        }

        if (is_array($rawRecipient)) {
            foreach ($rawRecipient as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return $rawRecipient;
                }

                if (is_object($item) && isset($item->email) && trim((string) $item->email) !== '') {
                    return $rawRecipient;
                }

                if (is_array($item) && isset($item['email']) && is_string($item['email']) && trim($item['email']) !== '') {
                    return $rawRecipient;
                }
            }
        }

        return null;
    }

    protected function defaultPostEmitRecipient(Invoice $invoice): ?string
    {
        $candidates = [
            $this->contactStringField($invoice->contact, ['email']),
            trim((string) ($invoice->contact_email ?? '')),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizedTomadorEmail($candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }


}
