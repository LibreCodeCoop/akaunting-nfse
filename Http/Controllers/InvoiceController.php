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
use LibreCodeCoop\NfsePHP\Config\CertConfig;
use LibreCodeCoop\NfsePHP\Config\EnvironmentConfig;
use LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use LibreCodeCoop\NfsePHP\Dto\DpsData;
use LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use LibreCodeCoop\NfsePHP\Exception\GatewayException;
use LibreCodeCoop\NfsePHP\Exception\NetworkException;
use LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
use LibreCodeCoop\NfsePHP\Http\NfseClient;
use LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;
use LibreCodeCoop\NfsePHP\Xml\XmlBuilder;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\WebDavClient;

class InvoiceController extends Controller
{
    private const INVOICE_NOTES_SETTING_KEY = 'invoice.notes';

    protected string $indexSortBy = 'due_at';

    protected string $indexSortDirection = 'desc';

    public function dashboard(): \Illuminate\View\View
    {
        $stats = $this->dashboardStats();

        return view('nfse::dashboard.index', compact('stats'));
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
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();
        $receiptStatusLabel = $this->translateReceiptStatus((string) ($receipt->status ?? ''));
        $suggestedDiscriminacao = $this->buildDiscriminacao($invoice);
        $emailDefaults = $this->servicePreviewEmailDefaults($invoice);
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        return view('nfse::invoices.show', compact('invoice', 'receipt', 'receiptStatusLabel', 'suggestedDiscriminacao', 'emailDefaults', 'artifacts'));
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
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        if (!isset($artifacts[$artifact]) || !is_array($artifacts[$artifact])) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_invalid_type'));
        }

        $artifactData = $artifacts[$artifact];
        $path = isset($artifactData['path']) && is_string($artifactData['path']) ? trim($artifactData['path']) : '';

        if ($path === '' || !($artifactData['exists'] ?? false)) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_not_found'));
        }

        try {
            $content = $this->makeWebDavClientFromSettings()->get($path);
        } catch (\Throwable) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.artifact_not_found'));
        }

        $mimeType = $artifact === 'xml' ? 'application/xml' : 'application/pdf';
        $extension = $artifact === 'xml' ? 'xml' : 'pdf';
        $resolvedNfseNumber = trim((string) ($receipt->nfse_number ?? ''));
        $suffix = $resolvedNfseNumber !== '' ? '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $resolvedNfseNumber) : '';
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

        return response()->json([
            'missing_items'    => [],
            'available_services' => $this->availableInvoiceServices($invoice),
            'default_service_id' => 0,
            'requires_split'   => (bool) ($itemFiscalProfile['requires_split'] ?? false),
            'suggested_description' => $this->buildDiscriminacao($invoice, $itemFiscalProfile['line_items'] ?? []),
            'email_defaults'   => $this->servicePreviewEmailDefaults($invoice),
        ]);
    }

    public function emit(Invoice $invoice, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $request = $this->currentRequest($request);
        $this->ensureInvoiceRelationsLoaded($invoice);

        if (!$this->invoiceHasLineItems($invoice)) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_no_items')));
        }

        $federalTaxReadiness = $this->federalTaxReadinessForInvoice($invoice);

        if (($federalTaxReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', $this->emitBlockedFederalTaxMessage($federalTaxReadiness['missing'] ?? [])));
        }

        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);
        $this->persistDefaultDescriptionFromRequest($request);

        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready')));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = $this->sandboxModeEnabled();
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues($invoice);
        $municipalTaxationCode = $this->normalizedMunicipalTaxationCode((string) $itemFiscalProfile['item_lista_servico']);

        $dps = $this->makeDpsData([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $ibge,
            'itemListaServico' => $municipalTaxationCode,
            'codigoTributacaoNacional' => (string) $itemFiscalProfile['codigo_tributacao_nacional'],
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => (string) $itemFiscalProfile['aliquota'],
            'discriminacao' => $this->buildDiscriminacao($invoice, $itemFiscalProfile['line_items'] ?? [], $customDiscriminacao),
            'documentoTomador' => $tomadorDocument,
            'nomeTomador' => $invoice->contact?->name ?? '',
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
            'tipoAmbiente' => $sandbox ? 2 : 1,
            'serie' => $this->dpsSerie($invoice),
            'numeroDps' => $this->dpsNumber($invoice),
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
        ]);

        $this->safeLogInfo('NFS-e emission payload', [
            'invoice_id' => $invoice->id,
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
            'tributos_fed_p' => $dps->totalTributosPercentualFederal,
            'tributos_est_p' => $dps->totalTributosPercentualEstadual,
            'tributos_mun_p' => $dps->totalTributosPercentualMunicipal,
        ]);

        $client = $this->makeClient($sandbox);

        try {
            $receipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_secret_store_failed')));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->gatewayErrorDetail($e);
            $xmlOrderDebug = $this->dpsXmlOrderDebug($dps);

            $this->safeLogError('NFS-e issuance rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
                'xml_order_debug' => $xmlOrderDebug,
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail));
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e issuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed')));
        } catch (PfxImportException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        $persistedReceipt = $this->storeEmittedReceipt($invoice, $receipt);
        $this->storeArtifacts($invoice, $receipt, $persistedReceipt, $client);
        $this->markInvoiceSentAfterEmission($invoice);
        $this->handlePostEmitEmail($request, $invoice, $persistedReceipt);
        $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($receipt);

        return $this->ajaxAwareRedirect(
            $request,
            redirect()->route('nfse.invoices.show', $invoice)
                ->with('success', trans('nfse::general.nfse_emitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $receipt->chaveAcesso])),
            ['partial_url' => route('nfse.invoices.emit-success', $invoice)],
        );
    }

    protected function markInvoiceSentAfterEmission(Invoice $invoice): void
    {
        event(new DocumentMarkedSent($invoice));
    }

    public function cancel(Invoice $invoice, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $request = $this->currentRequest($request);
        $receipt = $this->findReceiptForInvoice($invoice);

        $client = $this->makeClient($this->sandboxModeEnabled());
        $cancelReason = $this->cancellationReasonForGateway($request);
        $redirect = $this->cancellationRedirect($invoice, $request);

        try {
            $client->cancel($receipt->chave_acesso, $cancelReason);
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
        }

        $receipt->update(['status' => 'cancelled']);

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
            'nfse_show' => redirect()->route('nfse.invoices.show', $invoice),
            default => redirect()->route('nfse.invoices.index'),
        };
    }

    protected function cancellationReasonForGateway(?Request $request = null): string
    {
        $request = $this->currentRequest($request);
        $allowedReasons = $this->cancellationReasonOptions();

        if (!$request instanceof Request) {
            return (string) trans('nfse::general.cancel_motivo_default');
        }

        $allInput = method_exists($request, 'all') && is_array($request->all())
            ? $request->all()
            : [];

        $isDeleteMethod = method_exists($request, 'isMethod')
            ? $request->isMethod('delete')
            : false;

        $hasStructuredCancellationData = array_key_exists('cancel_reason', $allInput)
            || array_key_exists('cancel_justification', $allInput);

        $requiresStructuredCancellationData = $isDeleteMethod || $hasStructuredCancellationData;

        if (!$requiresStructuredCancellationData) {
            return (string) trans('nfse::general.cancel_motivo_default');
        }

        if (method_exists($request, 'validate')) {
            $validated = $request->validate(
                [
                    'cancel_reason' => ['required', 'string', 'max:120', 'in:' . implode(',', $allowedReasons)],
                    'cancel_justification' => ['required', 'string', 'max:1000'],
                ],
                [
                    'cancel_reason.required' => (string) trans('nfse::general.invoices.cancel_reason_required'),
                    'cancel_reason.in' => (string) trans('nfse::general.invoices.cancel_reason_invalid'),
                    'cancel_justification.required' => (string) trans('nfse::general.invoices.cancel_justification_required'),
                ],
            );

            $reason = trim((string) ($validated['cancel_reason'] ?? ''));
            $justification = trim((string) ($validated['cancel_justification'] ?? ''));

            return $reason . ' - ' . $justification;
        }

        $reason = trim((string) ($allInput['cancel_reason'] ?? ''));
        $justification = trim((string) ($allInput['cancel_justification'] ?? ''));

        if ($reason === '' || $justification === '' || !in_array($reason, $allowedReasons, true)) {
            return (string) trans('nfse::general.cancel_motivo_default');
        }

        return $reason . ' - ' . $justification;
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

        $client = $this->makeClient($this->sandboxModeEnabled());

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

            $this->storeArtifacts($invoice, $updatedReceipt, $receipt, $client);

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('success', trans('nfse::general.nfse_refreshed', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $updatedReceipt->chaveAcesso]));
        } catch (\Throwable) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_refresh_failed'));
        }
    }

    public function refreshAll(): RedirectResponse
    {
        $client = $this->makeClient($this->sandboxModeEnabled());
        $updated = 0;
        $failed = 0;

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
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready')));
        }

        $sandboxReemit = $this->sandboxModeEnabled();
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues($invoice);
        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        $municipalTaxationCode = $this->normalizedMunicipalTaxationCode((string) $itemFiscalProfile['item_lista_servico']);

        $dps = $this->makeDpsData([
            'cnpjPrestador' => (string) setting('nfse.cnpj_prestador'),
            'municipioIbge' => (string) setting('nfse.municipio_ibge'),
            'itemListaServico' => $municipalTaxationCode,
            'codigoTributacaoNacional' => (string) $itemFiscalProfile['codigo_tributacao_nacional'],
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => (string) $itemFiscalProfile['aliquota'],
            'discriminacao' => $this->buildDiscriminacao($invoice, $itemFiscalProfile['line_items'] ?? [], $customDiscriminacao),
            'documentoTomador' => $tomadorDocument,
            'nomeTomador' => $invoice->contact?->name ?? '',
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
        ]);

        $client = $this->makeClient($sandboxReemit);

        try {
            $newReceipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed')));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->gatewayErrorDetail($e);
            $xmlOrderDebug = $this->dpsXmlOrderDebug($dps);

            $this->safeLogError('NFS-e reissuance rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
                'xml_order_debug' => $xmlOrderDebug,
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail));
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e reissuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed')));
        } catch (PfxImportException) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        $persistedReceipt = $this->storeEmittedReceipt($invoice, $newReceipt, $receipt);
        $this->storeArtifacts($invoice, $newReceipt, $persistedReceipt, $client);
        $this->handlePostEmitEmail($request, $invoice, $persistedReceipt);
        $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($newReceipt);

        return $this->ajaxAwareRedirect(
            $request,
            redirect()->route('nfse.invoices.show', $invoice)
                ->with('success', trans('nfse::general.nfse_reemitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $newReceipt->chaveAcesso])),
            ['partial_url' => route('nfse.invoices.emit-success', $invoice)],
        );
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
            return $defaultDescription;
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

        $hasSuccess = isset($redirect->flash['success']);
        $hasError = !$hasSuccess && (isset($redirect->flash['error']) || isset($redirect->flash['warning']));

        if ($hasError) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $redirect->flash['error'] ?? $redirect->flash['warning'] ?? '',
                'redirect' => false,
                'data' => null,
            ]);
        }

        if (isset($redirect->flash['success'])) {
            session()->flash('success', $redirect->flash['success']);
        }

        if (isset($redirect->flash['info'])) {
            session()->flash('info', $redirect->flash['info']);
        }

        if (isset($redirect->flash['warning'])) {
            session()->flash('warning', $redirect->flash['warning']);
        }

        return response()->json(array_merge([
            'success' => true,
            'error' => false,
            'message' => (string) ($redirect->flash['success'] ?? ''),
            'redirect' => $redirect->getTargetUrl(),
            'data' => null,
        ], $extra));
    }
    protected function normalizeDescriptionText(string $value): ?string
    {
        $normalizedLineBreaks = str_replace(["\r\n", "\r"], "\n", $value);
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
     * @return array{item_lista_servico:string,codigo_tributacao_nacional:string,aliquota:string,line_items:list<string>,requires_split:bool}
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

        $profileMap = $this->invoiceItemFiscalProfileMap($companyId, $itemIds);
        $taxRateMap = $this->invoiceItemTaxRateMap($itemIds);

        $lineItems = [];
        $signatures = [];
        $selected = [
            'item_lista_servico' => $this->itemListaServico($defaultService),
            'codigo_tributacao_nacional' => $this->nationalTaxCode($defaultService),
            'aliquota' => $this->normalizedAliquota($defaultService),
        ];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            $itemName = trim((string) ($item['name'] ?? ''));
            $itemName = $itemName !== '' ? $itemName : trans('general.na');

            $profile = $itemId > 0 ? ($profileMap[$itemId] ?? null) : null;
            $serviceCode = preg_replace('/\D+/', '', (string) ($profile['item_lista_servico'] ?? '')) ?: '';

            if ($serviceCode === '') {
                $serviceCode = $this->itemListaServico($defaultService);
            }

            $serviceCode = substr($serviceCode, 0, 4);

            $nationalCode = preg_replace('/\D+/', '', (string) ($profile['codigo_tributacao_nacional'] ?? '')) ?: '';
            if ($nationalCode === '') {
                $nationalCode = $this->nationalTaxCode((object) [
                    'item_lista_servico' => $serviceCode,
                ]);
            }

            $aliquota = $itemId > 0 ? ($taxRateMap[$itemId] ?? '') : '';
            if ($aliquota === '') {
                $aliquota = $this->normalizedAliquota($defaultService);
            }

            if ($serviceCode !== '') {
                $lineItems[] = '[' . $serviceCode . '] ' . $itemName;
            } else {
                $lineItems[] = $itemName;
            }

            $signature = $serviceCode . '|' . $nationalCode . '|' . $aliquota;

            if ($signature !== '||') {
                $signatures[$signature] = true;

                if ($selected['item_lista_servico'] === '' || $selected['item_lista_servico'] === $this->itemListaServico($defaultService)) {
                    $selected = [
                        'item_lista_servico' => $serviceCode,
                        'codigo_tributacao_nacional' => $nationalCode,
                        'aliquota' => $aliquota,
                    ];
                }
            }
        }

        return [
            'item_lista_servico' => $selected['item_lista_servico'] !== '' ? $selected['item_lista_servico'] : $this->itemListaServico($defaultService),
            'codigo_tributacao_nacional' => $selected['codigo_tributacao_nacional'] !== '' ? $selected['codigo_tributacao_nacional'] : $this->nationalTaxCode($defaultService),
            'aliquota' => $selected['aliquota'] !== '' ? $selected['aliquota'] : $this->normalizedAliquota($defaultService),
            'line_items' => $lineItems,
            'requires_split' => count($signatures) > 1,
        ];
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, array{item_lista_servico:string,codigo_tributacao_nacional:string}>
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
                            'item_lista_servico' => preg_replace('/\D+/', '', (string) ($profile->item_lista_servico ?? '')) ?: '',
                            'codigo_tributacao_nacional' => preg_replace('/\D+/', '', (string) ($profile->codigo_tributacao_nacional ?? '')) ?: '',
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
    protected function federalTaxReadinessForInvoice(Invoice $invoice): array
    {
        $requiredBuckets = $this->requiredFederalTaxBucketsForEmission();

        if ($requiredBuckets === []) {
            return [
                'isReady' => true,
                'missing' => [],
            ];
        }

        $snapshot = $this->invoiceFederalTaxSnapshot($invoice, (float) ($invoice->amount ?? 0.0));
        $bucketToSnapshotKey = [
            'pis' => 'pis_value',
            'cofins' => 'cofins_value',
            'irrf' => 'irrf_value',
            'csll' => 'csll_value',
        ];

        $missing = [];

        foreach ($requiredBuckets as $bucket) {
            $snapshotKey = $bucketToSnapshotKey[$bucket] ?? null;

            if ($snapshotKey === null) {
                continue;
            }

            if (($snapshot[$snapshotKey] ?? '') === '') {
                $missing[] = $bucket;
            }
        }

        return [
            'isReady' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * @return list<string>
     */
    protected function requiredFederalTaxBucketsForEmission(): array
    {
        if (!$this->enforceFederalItemTaxes()) {
            return [];
        }

        $required = [];

        $situacaoTributaria = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_situacao_tributaria', ''));

        if ($situacaoTributaria !== '' && $situacaoTributaria !== '0') {
            $required[] = 'pis';
            $required[] = 'cofins';
        }

        $tipoRetencao = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_tipo_retencao', ''));

        if (in_array($tipoRetencao, ['3', '7', '8', '9'], true)) {
            $required[] = 'csll';
        }

        return array_values(array_unique($required));
    }

    protected function enforceFederalItemTaxes(): bool
    {
        $configured = setting('nfse.enforce_item_federal_taxes', true);

        if (is_bool($configured)) {
            return $configured;
        }

        if (is_numeric($configured)) {
            return (int) $configured === 1;
        }

        if (is_string($configured)) {
            $normalized = strtolower(trim($configured));

            if ($normalized === '' || in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return true;
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

    protected function normalizedTomadorDocument(?string $document): string
    {
        $digits = preg_replace('/\D+/', '', (string) $document) ?: '';

        if (in_array(strlen($digits), [11, 14], true)) {
            return $digits;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function makeDpsData(array $payload): DpsData
    {
        $constructor = new \ReflectionMethod(DpsData::class, '__construct');
        $supportedPayload = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $payload)) {
                $supportedPayload[$name] = $payload[$name];
            }
        }

        return new DpsData(...$supportedPayload);
    }

    /**
     * @return array{codigo_municipio: string, cep: string, logradouro: string, numero: string, complemento: string, bairro: string, inscricao_municipal: string, telefone: string, email: string}
     */
    protected function tomadorPayload(?object $contact): array
    {
        $codigoMunicipio = $this->normalizedTomadorMunicipioIbge($contact);
        $cep = $this->normalizedTomadorCep($this->contactStringField($contact, ['zip_code', 'cep']));

        $logradouro = '';
        $numero = '';
        $complemento = '';
        $bairro = '';

        if ($codigoMunicipio !== '' && $cep !== '') {
            $logradouro = $this->contactStringField($contact, ['address', 'logradouro']);
            $numero = $this->contactStringField($contact, ['number', 'numero']);
            $complemento = $this->contactStringField($contact, ['complement', 'complemento']);
            $bairro = $this->contactStringField($contact, ['district', 'bairro', 'neighborhood']);
        } else {
            $codigoMunicipio = '';
            $cep = '';
        }

        return [
            'codigo_municipio' => $codigoMunicipio,
            'cep' => $cep,
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => $complemento,
            'bairro' => $bairro,
            'inscricao_municipal' => $this->contactStringField($contact, ['inscricao_municipal', 'municipal_registration', 'im']),
            'telefone' => $this->normalizedTomadorTelefone($this->contactStringField($contact, ['phone', 'telefone'])),
            'email' => $this->normalizedTomadorEmail($this->contactStringField($contact, ['email'])),
        ];
    }

    protected function normalizedTomadorMunicipioIbge(?object $contact): string
    {
        $raw = $this->contactStringField($contact, ['municipio_ibge', 'city_ibge', 'ibge_code', 'city_code', 'city']);
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
        $settings = is_array($settings) ? $settings : [];
        $cnpj = (string) ($settings['cnpj_prestador'] ?? '');
        $certificatePath = $cnpj !== '' ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx') : '';

        $checklist = [
            'cnpj_prestador' => $cnpj !== '',
            'municipio_ibge' => ((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => $this->itemListaServico() !== '',
            'certificate' => $certificatePath !== '' && is_file($certificatePath),
            'certificate_secret' => $this->hasCertificateSecret($cnpj),
        ];

        return [
            'checklist' => $checklist,
            'isReady' => !in_array(false, $checklist, true),
        ];
    }

    protected function itemListaServico(?object $defaultService = null): string
    {
        $serviceCode = preg_replace('/\D+/', '', (string) ($defaultService->item_lista_servico ?? '')) ?: '';

        if ($serviceCode !== '') {
            return substr($serviceCode, 0, 4);
        }

        return preg_replace('/\D+/', '', (string) setting('nfse.item_lista_servico', '0107')) ?: '0107';
    }

    protected function nationalTaxCode(?object $defaultService = null): string
    {
        $configured = preg_replace('/\D+/', '', (string) ($defaultService->codigo_tributacao_nacional ?? '')) ?: '';

        if ($configured === '') {
            $configured = preg_replace('/\D+/', '', (string) setting('nfse.codigo_tributacao_nacional', '')) ?: '';
        }

        if ($configured === '') {
            $municipalCode = $this->itemListaServico($defaultService);

            if ($municipalCode !== '') {
                $configured = str_pad(substr($municipalCode, 0, 4), 4, '0', STR_PAD_LEFT) . '01';
            }
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

    protected function dpsSerie(Invoice $invoice): string
    {
        return '00001';
    }

    protected function dpsNumber(Invoice $invoice): string
    {
        $invoiceId = isset($invoice->id) ? (int) $invoice->id : 0;

        return (string) max($invoiceId, 1);
    }

    protected function dpsNumberForReemit(Invoice $invoice): string
    {
        $base = $this->dpsNumber($invoice);
        $microtimeDigits = preg_replace('/\D+/', '', sprintf('%.6f', microtime(true))) ?: '';
        $candidate = $base . substr($microtimeDigits, -8);
        $digits = preg_replace('/\D+/', '', $candidate) ?: $base;

        if (strlen($digits) > 15) {
            $digits = substr($digits, -15);
        }

        return ltrim($digits, '0') !== '' ? ltrim($digits, '0') : '1';
    }

    protected function competenceDate(Invoice $invoice): ?string
    {
        $issuedAt = $invoice->issued_at ?? null;

        if ($issuedAt instanceof \DateTimeInterface) {
            return $issuedAt->format('Y-m-d');
        }

        if (is_string($issuedAt) && $issuedAt !== '') {
            $timestamp = strtotime($issuedAt);

            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return null;
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

        return in_array($configured, [1, 2], true) ? $configured : 2;
    }

    protected function federalPayloadValues(Invoice $invoice): array
    {
        $invoiceAmount = (float) ($invoice->amount ?? 0.0);
        $federalMode = strtolower((string) setting('nfse.tributacao_federal_mode', 'per_invoice_amounts'));
        $invoiceFederalTaxes = $this->invoiceFederalTaxSnapshot($invoice, $invoiceAmount);
        $situacaoTributaria = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_situacao_tributaria', ''));
        $tipoRetencao = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_tipo_retencao', ''));
        $valorCsllRetencao = $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_csll');

        if ($valorCsllRetencao === '' && $invoiceFederalTaxes['csll_value'] !== '') {
            $valorCsllRetencao = $invoiceFederalTaxes['csll_value'];
        }

        if (in_array($tipoRetencao, ['4', '5', '6'], true) && $valorCsllRetencao === '') {
            // Gateway currently rejects tpRetPisCofins != 0 without vRetCSLL.
            // When configured CSLL retention is zero, fallback avoids invalid payloads.
            $tipoRetencao = '0';
        }

        $isSimplesNacionalOptant = $this->normalizedOpcaoSimplesNacional() === 2;

        $totalTributosPercentualFederal = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_fed_sn' : 'nfse.tributos_fed_p', ''));
        $totalTributosPercentualEstadual = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_est_sn' : 'nfse.tributos_est_p', ''));
        $totalTributosPercentualMunicipal = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_mun_sn' : 'nfse.tributos_mun_p', ''));

        if ($totalTributosPercentualFederal === '' && $invoiceFederalTaxes['federal_percent'] !== '') {
            $totalTributosPercentualFederal = $invoiceFederalTaxes['federal_percent'];
        }

        $indicadorTributacao = (
            $totalTributosPercentualFederal !== '' ||
            $totalTributosPercentualEstadual !== '' ||
            $totalTributosPercentualMunicipal !== ''
        ) ? 2 : 0;

        if ($indicadorTributacao === 2) {
            // RNG6110 schema validation requires the tributos percentage sequence to be present and ordered.
            $totalTributosPercentualFederal = $totalTributosPercentualFederal !== '' ? $totalTributosPercentualFederal : '0.00';
            $totalTributosPercentualEstadual = $totalTributosPercentualEstadual !== '' ? $totalTributosPercentualEstadual : '0.00';
            $totalTributosPercentualMunicipal = $totalTributosPercentualMunicipal !== '' ? $totalTributosPercentualMunicipal : '0.00';
        }

        $valorIrrf = $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_irrf');
        if ($valorIrrf === '' && $invoiceFederalTaxes['irrf_value'] !== '') {
            $valorIrrf = $invoiceFederalTaxes['irrf_value'];
        }

        if ($situacaoTributaria === '' || $situacaoTributaria === '0') {
            return $this->finalizeFederalPayload([
                'federalPiscofinsSituacaoTributaria' => '',
                'federalPiscofinsTipoRetencao' => '',
                'federalPiscofinsBaseCalculo' => '',
                'federalPiscofinsAliquotaPis' => '',
                'federalPiscofinsValorPis' => '',
                'federalPiscofinsAliquotaCofins' => '',
                'federalPiscofinsValorCofins' => '',
                'federalValorIrrf' => $valorIrrf,
                'federalValorCsll' => $valorCsllRetencao,
                // Produção restrita currently rejects vRetCP (RNG6110), so keep CP as UI/config only.
                'federalValorCp' => '',
                'indicadorTributacao' => $indicadorTributacao,
                'totalTributosPercentualFederal' => $totalTributosPercentualFederal,
                'totalTributosPercentualEstadual' => $totalTributosPercentualEstadual,
                'totalTributosPercentualMunicipal' => $totalTributosPercentualMunicipal,
            ]);
        }

        $aliquotaPis = $this->normalizedFederalDecimal(setting('nfse.federal_piscofins_aliquota_pis', ''));
        $aliquotaCofins = $this->normalizedFederalDecimal(setting('nfse.federal_piscofins_aliquota_cofins', ''));

        if (($federalMode === 'per_invoice_amounts' || $aliquotaPis === '') && $invoiceFederalTaxes['pis_rate'] !== '') {
            $aliquotaPis = $invoiceFederalTaxes['pis_rate'];
        }

        if (($federalMode === 'per_invoice_amounts' || $aliquotaCofins === '') && $invoiceFederalTaxes['cofins_rate'] !== '') {
            $aliquotaCofins = $invoiceFederalTaxes['cofins_rate'];
        }

        $valorPis = $aliquotaPis !== ''
            ? number_format($invoiceAmount * (float) $aliquotaPis / 100, 2, '.', '')
            : '';

        if (($federalMode === 'per_invoice_amounts' || $valorPis === '') && $invoiceFederalTaxes['pis_value'] !== '') {
            $valorPis = $invoiceFederalTaxes['pis_value'];
        }

        $valorCofins = $aliquotaCofins !== ''
            ? number_format($invoiceAmount * (float) $aliquotaCofins / 100, 2, '.', '')
            : '';

        if (($federalMode === 'per_invoice_amounts' || $valorCofins === '') && $invoiceFederalTaxes['cofins_value'] !== '') {
            $valorCofins = $invoiceFederalTaxes['cofins_value'];
        }

        return $this->finalizeFederalPayload([
            'federalPiscofinsSituacaoTributaria' => $situacaoTributaria,
            'federalPiscofinsTipoRetencao' => $tipoRetencao,
            'federalPiscofinsBaseCalculo' => number_format($invoiceAmount, 2, '.', ''),
            'federalPiscofinsAliquotaPis' => $aliquotaPis,
            'federalPiscofinsValorPis' => $valorPis,
            'federalPiscofinsAliquotaCofins' => $aliquotaCofins,
            'federalPiscofinsValorCofins' => $valorCofins,
            'federalValorIrrf' => $valorIrrf,
            'federalValorCsll' => $tipoRetencao !== '0'
                ? $valorCsllRetencao
                : '',
            // Produção restrita currently rejects vRetCP (RNG6110), so keep CP as UI/config only.
            'federalValorCp' => '',
            'indicadorTributacao' => $indicadorTributacao,
            'totalTributosPercentualFederal' => $totalTributosPercentualFederal,
            'totalTributosPercentualEstadual' => $totalTributosPercentualEstadual,
            'totalTributosPercentualMunicipal' => $totalTributosPercentualMunicipal,
        ]);
    }

    /**
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    protected function invoiceFederalTaxSnapshot(Invoice $invoice, float $invoiceAmount): array
    {
        $totals = [
            'pis' => 0.0,
            'cofins' => 0.0,
            'irrf' => 0.0,
            'csll' => 0.0,
            'cp' => 0.0,
        ];

        $rateTotals = [
            'pis' => 0.0,
            'cofins' => 0.0,
            'irrf' => 0.0,
            'csll' => 0.0,
            'cp' => 0.0,
        ];

        $seenTaxes = [];
        $seenRateKeys = [];
        $taxRateById = [];

        foreach ($this->invoiceItemsAsArray($invoice) as $item) {
            $taxes = [];

            if (is_array($item)) {
                $taxes = array_merge(
                    is_array($item['taxes'] ?? null) ? $item['taxes'] : [],
                    is_array($item['item_taxes'] ?? null) ? $item['item_taxes'] : []
                );
            }

            if (is_object($item)) {
                $itemTaxes = [];

                if (isset($item->taxes) && is_iterable($item->taxes)) {
                    foreach ($item->taxes as $tax) {
                        $itemTaxes[] = $tax;
                    }
                }

                if (isset($item->item_taxes) && is_iterable($item->item_taxes)) {
                    foreach ($item->item_taxes as $tax) {
                        $itemTaxes[] = $tax;
                    }
                }

                if ($itemTaxes === [] && method_exists($item, 'item_taxes')) {
                    try {
                        $itemTaxRelation = $item->item_taxes();

                        if (is_object($itemTaxRelation) && is_callable([$itemTaxRelation, 'get'])) {
                            $resolvedItemTaxes = $itemTaxRelation->get();

                            if (is_iterable($resolvedItemTaxes)) {
                                foreach ($resolvedItemTaxes as $tax) {
                                    $itemTaxes[] = $tax;
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // Ignore relation resolution errors and keep snapshot best-effort.
                    }
                }

                if ($itemTaxes === [] && method_exists($item, 'taxes')) {
                    try {
                        $taxRelation = $item->taxes();

                        if (is_object($taxRelation) && is_callable([$taxRelation, 'get'])) {
                            $resolvedTaxes = $taxRelation->get();

                            if (is_iterable($resolvedTaxes)) {
                                foreach ($resolvedTaxes as $tax) {
                                    $itemTaxes[] = $tax;
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // Ignore relation resolution errors and keep snapshot best-effort.
                    }
                }

                $taxes = array_merge($taxes, $itemTaxes);
            }

            foreach ($taxes as $tax) {
                $name = trim((string) (is_array($tax) ? ($tax['name'] ?? '') : ($tax->name ?? '')));
                $amountRaw = is_array($tax) ? ($tax['amount'] ?? null) : ($tax->amount ?? null);

                if ($name === '' || !is_numeric($amountRaw)) {
                    continue;
                }

                $amount = (float) $amountRaw;

                if ($amount <= 0) {
                    continue;
                }

                $signature = strtolower($name) . '|' . number_format($amount, 4, '.', '');

                if (isset($seenTaxes[$signature])) {
                    continue;
                }

                $seenTaxes[$signature] = true;
                $bucket = $this->federalTaxBucketFromName($name);

                if ($bucket === null) {
                    continue;
                }

                $totals[$bucket] += $amount;

                $resolvedRate = $this->resolveFederalTaxRate($tax, $taxRateById);

                if ($resolvedRate === null || $resolvedRate <= 0) {
                    continue;
                }

                $rateKey = $this->federalTaxRateDedupKey($tax, $bucket, $name, $resolvedRate);

                if (isset($seenRateKeys[$rateKey])) {
                    continue;
                }

                $seenRateKeys[$rateKey] = true;
                $rateTotals[$bucket] += $resolvedRate;
            }
        }

        $federalTotal = $totals['pis'] + $totals['cofins'] + $totals['irrf'] + $totals['csll'] + $totals['cp'];
        $federalRateTotal = $rateTotals['pis'] + $rateTotals['cofins'] + $rateTotals['irrf'] + $rateTotals['csll'] + $rateTotals['cp'];

        $pisRate = $rateTotals['pis'] > 0
            ? number_format($rateTotals['pis'], 2, '.', '')
            : $this->formattedTaxRate($totals['pis'], $invoiceAmount);

        $cofinsRate = $rateTotals['cofins'] > 0
            ? number_format($rateTotals['cofins'], 2, '.', '')
            : $this->formattedTaxRate($totals['cofins'], $invoiceAmount);

        $federalPercent = $federalRateTotal > 0
            ? number_format($federalRateTotal, 2, '.', '')
            : $this->formattedTaxRate($federalTotal, $invoiceAmount);

        return [
            'pis_value' => $this->formattedPositiveDecimal($totals['pis']),
            'pis_rate' => $pisRate,
            'cofins_value' => $this->formattedPositiveDecimal($totals['cofins']),
            'cofins_rate' => $cofinsRate,
            'irrf_value' => $this->formattedPositiveDecimal($totals['irrf']),
            'csll_value' => $this->formattedPositiveDecimal($totals['csll']),
            'federal_percent' => $federalPercent,
        ];
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

    protected function federalTaxRateDedupKey(mixed $tax, string $bucket, string $name, float $rate): string
    {
        $taxIdRaw = is_array($tax)
            ? ($tax['tax_id'] ?? null)
            : ($tax->tax_id ?? null);

        if (is_numeric($taxIdRaw) && (int) $taxIdRaw > 0) {
            return $bucket . '|tax_id:' . (int) $taxIdRaw;
        }

        return $bucket . '|name:' . strtolower($name) . '|rate:' . number_format($rate, 4, '.', '');
    }

    protected function federalTaxBucketFromName(string $name): ?string
    {
        $normalizedName = $this->normalizeTaxMatcherString($name);

        if ($normalizedName === '') {
            return null;
        }

        $bucketByCodeHint = $this->federalTaxBucketFromCodeHint($normalizedName);

        if ($bucketByCodeHint !== null) {
            return $bucketByCodeHint;
        }

        if ($this->containsAnyTaxTerm($normalizedName, [
            'cofins',
            'contribuicao para o financiamento da seguridade social',
            'financiamento da seguridade social',
        ])) {
            return 'cofins';
        }

        if ($this->containsAnyTaxTerm($normalizedName, [
            'irrf',
            'imposto de renda retido na fonte',
            'renda retida na fonte',
            'imposto de renda fonte',
        ])) {
            return 'irrf';
        }

        if ($this->containsAnyTaxTerm($normalizedName, [
            'csll',
            'contribuicao social sobre o lucro liquido',
            'contribuicao social lucro liquido',
        ])) {
            return 'csll';
        }

        if ($this->containsAnyTaxTerm($normalizedName, [
            'inss',
            'contribuicao previd',
            'contribuicao previdenciaria',
            'previdencia social',
        ])) {
            return 'cp';
        }

        if ($this->containsAnyTaxTerm($normalizedName, [
            'pis',
            'pasep',
            'programa de integracao social',
            'programa de formacao do patrimonio do servidor publico',
        ])) {
            return 'pis';
        }

        return null;
    }

    protected function federalTaxBucketFromCodeHint(string $normalizedName): ?string
    {
        if (preg_match('/\b(?:cod|codigo|cst)\s*[:\-]?\s*(pis|cofins|irrf|csll|inss|cp)\b/', $normalizedName, $matches) === 1) {
            return match ($matches[1]) {
                'pis' => 'pis',
                'cofins' => 'cofins',
                'irrf' => 'irrf',
                'csll' => 'csll',
                'inss', 'cp' => 'cp',
                default => null,
            };
        }

        if (preg_match('/\[(pis|cofins|irrf|csll|inss|cp)\]/', $normalizedName, $matches) === 1) {
            return match ($matches[1]) {
                'pis' => 'pis',
                'cofins' => 'cofins',
                'irrf' => 'irrf',
                'csll' => 'csll',
                'inss', 'cp' => 'cp',
                default => null,
            };
        }

        return null;
    }

    /**
     * @param list<string> $terms
     */
    protected function containsAnyTaxTerm(string $normalizedName, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($normalizedName, $term)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeTaxMatcherString(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);

        $collapsed = preg_replace('/[^a-z0-9\[\]\-:\s]+/', ' ', $normalized);
        $collapsed = is_string($collapsed) ? $collapsed : $normalized;

        $singleSpaced = preg_replace('/\s+/', ' ', $collapsed);

        return trim(is_string($singleSpaced) ? $singleSpaced : $collapsed);
    }

    protected function formattedPositiveDecimal(float $value): string
    {
        if ($value <= 0) {
            return '';
        }

        return number_format($value, 2, '.', '');
    }

    protected function formattedTaxRate(float $taxValue, float $baseAmount): string
    {
        if ($taxValue <= 0 || $baseAmount <= 0) {
            return '';
        }

        return number_format(($taxValue / $baseAmount) * 100, 2, '.', '');
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

    protected function normalizedMunicipalTaxationCode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';

        if ($digits === '') {
            return '';
        }

        // SEFIN schema for cTribMun rejects 4-digit LC116-like values such as 0107.
        // Keep the municipal code in up to 3 digits to satisfy TCCodTribMun.
        return substr($digits, -3);
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
        $cnpj = (string) setting('nfse.cnpj_prestador', '');

        return new NfseClient(
            environment: new EnvironmentConfig(sandboxMode: $sandboxMode),
            cert:        new CertConfig(
                cnpj:      $cnpj,
                pfxPath:   storage_path('app/nfse/pfx/' . $cnpj . '.pfx'),
                vaultPath: 'pfx/' . $cnpj,
                transportCertificatePath: $this->existingProjectRootPath('client.crt.pem'),
                transportPrivateKeyPath: $this->existingProjectRootPath('client.key.pem'),
            ),
            secretStore: $this->makeSecretStore(),
        );
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
        $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($receipt);

        if ($existingReceipt instanceof NfseReceipt) {
            $existingReceipt->update([
                'nfse_number' => $resolvedReceiptNumber,
                'chave_acesso' => $receipt->chaveAcesso,
                'data_emissao' => $receipt->dataEmissao,
                'codigo_verificacao' => $receipt->codigoVerificacao,
                'status' => 'emitted',
            ]);
        }

        return NfseReceipt::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'nfse_number' => $resolvedReceiptNumber,
                'chave_acesso' => $receipt->chaveAcesso,
                'data_emissao' => $receipt->dataEmissao,
                'codigo_verificacao' => $receipt->codigoVerificacao,
                'status' => 'emitted',
            ]
        );
    }

    protected function storeArtifacts(Invoice $invoice, ReceiptData $receipt, NfseReceipt $nfseReceipt, NfseClientInterface $client): void
    {
        if (!$this->webDavEnabled() || $receipt->chaveAcesso === '') {
            return;
        }

        $webDavClient = $this->makeWebDavClientFromSettings();
        $basePath = $this->buildWebDavArtifactBasePath($invoice, $receipt);
        $xmlPath = null;
        $danfsePath = null;

        if ($this->webDavStoreXmlEnabled() && $receipt->rawXml !== null && trim($receipt->rawXml) !== '') {
            try {
                $candidateXmlPath = $this->buildWebDavArtifactFilePath($basePath, $invoice, $receipt, 'xml');
                $webDavClient->put($candidateXmlPath, $receipt->rawXml);
                $xmlPath = $candidateXmlPath;
            } catch (\Throwable $throwable) {
                $this->safeLogError('NFS-e XML artifact storage failed', [
                    'invoice_id' => $invoice->id,
                    'chave_acesso' => $receipt->chaveAcesso,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        if ($this->webDavStorePdfEnabled()) {
            try {
                $danfseGetter = [$client, 'getDanfse'];

                if (is_callable($danfseGetter)) {
                    $danfse = $this->fetchDanfseWithRetry($client, $receipt->chaveAcesso);

                    if (is_string($danfse) && $danfse !== '') {
                        $candidateDanfsePath = $this->buildWebDavArtifactFilePath($basePath, $invoice, $receipt, 'pdf');
                        $webDavClient->put($candidateDanfsePath, $danfse);
                        $danfsePath = $candidateDanfsePath;
                    }
                }
            } catch (\Throwable $throwable) {
                $this->safeLogError('NFS-e DANFSE artifact storage failed', [
                    'invoice_id' => $invoice->id,
                    'chave_acesso' => $receipt->chaveAcesso,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        if ($xmlPath !== null || $danfsePath !== null) {
            try {
                $nfseReceipt->update([
                    'xml_webdav_path' => $xmlPath,
                    'danfse_webdav_path' => $danfsePath,
                ]);
            } catch (\Throwable $throwable) {
                $this->safeLogError('NFS-e artifact path persistence failed', [
                    'invoice_id' => $invoice->id,
                    'chave_acesso' => $receipt->chaveAcesso,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }
    }

    protected function fetchDanfseWithRetry(NfseClientInterface $client, string $chaveAcesso, int $maxAttempts = 2): string
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                return $this->fetchDanfseArtifact($client, $chaveAcesso);
            } catch (\Throwable $throwable) {
                $lastError = $throwable;

                if (!$this->shouldRetryDanfseRetrieval($throwable, $attempt, $maxAttempts)) {
                    throw $throwable;
                }
            }
        }

        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Unexpected DANFSE retrieval retry flow termination.');
    }

    protected function fetchDanfseArtifact(NfseClientInterface $client, string $chaveAcesso): string
    {
        try {
            return $client->getDanfse($chaveAcesso);
        } catch (\Throwable $throwable) {
            if (!$this->isDanfseHttp496($throwable)) {
                throw $throwable;
            }

            // Controller-isolation unit tests must stay fully local and deterministic.
            if (class_exists(\Modules\Nfse\Http\Controllers\ControllerIsolationState::class, false)) {
                throw $throwable;
            }

            $lastError = $throwable;

            foreach ($this->danfseFallbackUrls($chaveAcesso) as $fallbackUrl) {
                try {
                    return $this->downloadDanfseFromUrl($fallbackUrl);
                } catch (\Throwable $fallbackError) {
                    $lastError = $fallbackError;
                }
            }

            throw $lastError;
        }
    }

    /**
     * @return list<string>
     */
    protected function danfseFallbackUrls(string $chaveAcesso): array
    {
        return [
            'https://www.producaorestrita.nfse.gov.br/EmissorNacional/Notas/Download/DANFSe/' . $chaveAcesso,
            'https://www.nfse.gov.br/EmissorNacional/Notas/Download/DANFSe/' . $chaveAcesso,
            'https://adn.producaorestrita.nfse.gov.br/danfse/' . $chaveAcesso,
            'https://adn.nfse.gov.br/danfse/' . $chaveAcesso,
        ];
    }

    protected function downloadDanfseFromUrl(string $url): string
    {
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];

        $transportCertPath = $this->existingProjectRootPath('client.crt.pem');
        $transportKeyPath = $this->existingProjectRootPath('client.key.pem');

        if (is_string($transportCertPath) && $transportCertPath !== '' && is_string($transportKeyPath) && $transportKeyPath !== '') {
            $sslOptions['local_cert'] = $transportCertPath;
            $sslOptions['local_pk'] = $transportKeyPath;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/pdf\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => $sslOptions,
        ]);

        $http_response_header = [];
        $body = file_get_contents($url, false, $context);
        $status = $this->parseHttpStatusCode($http_response_header);

        if ($body === false || $status >= 400 || $status === 0) {
            throw new \RuntimeException('ADN gateway returned error for DANFSE retrieval (HTTP ' . $status . ')');
        }

        if ($body === '') {
            throw new \RuntimeException('ADN gateway returned empty body for DANFSE retrieval');
        }

        return $body;
    }

    /**
     * @param list<string> $headers
     */
    protected function parseHttpStatusCode(array $headers): int
    {
        for ($index = count($headers) - 1; $index >= 0; $index--) {
            $headerLine = $headers[$index] ?? null;

            if (!is_string($headerLine)) {
                continue;
            }

            if (preg_match('/HTTP\/[\d.]+ (\d{3})/', $headerLine, $matches) === 1) {
                return (int) ($matches[1] ?? 0);
            }
        }

        return 0;
    }

    protected function isDanfseHttp496(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'http 496') || str_contains($message, 'http status 496');
    }

    protected function shouldRetryDanfseRetrieval(\Throwable $throwable, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        return $this->isDanfseHttp496($throwable);
    }

    /**
     * @return array{
     *   danfse: array{path: ?string, exists: bool, source: ?string, download_url: ?string},
     *   xml: array{path: ?string, exists: bool, source: ?string, download_url: ?string}
     * }
     */
    protected function resolveReceiptArtifacts(Invoice $invoice, NfseReceipt $receipt): array
    {
        $receiptData = $this->receiptDataFromModel($receipt);
        $basePath = $this->buildWebDavArtifactBasePath($invoice, $receiptData);

        return [
            'danfse' => $this->resolveSingleReceiptArtifact($invoice, $receipt, $receiptData, $basePath, 'danfse', 'danfse_webdav_path', 'pdf'),
            'xml' => $this->resolveSingleReceiptArtifact($invoice, $receipt, $receiptData, $basePath, 'xml', 'xml_webdav_path', 'xml'),
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

        $exists = $this->webDavEnabled() ? $this->webDavPathExists($path) : false;

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
        $template = trim((string) setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}'));
        if ($template === '') {
            $template = 'nfse/{cnpj}/{year}/{month}/{day}';
        }

        return trim(strtr($template, $this->buildWebDavArtifactTemplateReplacements($invoice, $receipt)), '/');
    }

    protected function buildWebDavArtifactFilePath(string $basePath, Invoice $invoice, ReceiptData $receipt, string $extension): string
    {
        $template = trim((string) setting('nfse.webdav_filename_template', '{chave_acesso}'));

        if ($template === '') {
            $template = '{chave_acesso}';
        }

        $fileName = trim(strtr($template, $this->buildWebDavArtifactTemplateReplacements($invoice, $receipt)), '/');
        $fileName = trim($fileName, '.');

        if ($fileName === '') {
            $fileName = 'nao-informado';
        }

        if (!str_ends_with(strtolower($fileName), '.' . strtolower($extension))) {
            $fileName .= '.' . $extension;
        }

        return $basePath . '/' . $fileName;
    }

    /**
     * @return array<string, string>
     */
    protected function buildWebDavArtifactTemplateReplacements(Invoice $invoice, ReceiptData $receipt): array
    {
        $resolvedNfseNumber = $this->resolveReceiptNfseNumber($receipt);

        $date = null;
        try {
            $date = new \DateTimeImmutable($receipt->dataEmissao);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable('now');
        }

        return [
            '{cnpj}' => (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{day}' => $date->format('d'),
            '{month_name}' => $this->monthNameByNumber((int) $date->format('n')),
            '{nfse_number}' => $this->sanitizePathSegment($resolvedNfseNumber !== '' ? $resolvedNfseNumber : 'sem-numero'),
            '{chave_acesso}' => $this->sanitizePathSegment($receipt->chaveAcesso !== '' ? $receipt->chaveAcesso : 'sem-chave-acesso'),
            '{customer_name}' => $this->sanitizePathSegment((string) ($invoice->contact?->name ?? 'sem-cliente')),
        ];
    }

    protected function resolveReceiptNfseNumber(ReceiptData $receipt): string
    {
        $numberFromGateway = trim($receipt->nfseNumber);

        if ($numberFromGateway !== '') {
            return $numberFromGateway;
        }

        return $this->extractNfseNumberFromRawXml($receipt->rawXml) ?? '';
    }

    protected function extractNfseNumberFromRawXml(?string $rawXml): ?string
    {
        if (!is_string($rawXml) || trim($rawXml) === '') {
            return null;
        }

        if (preg_match('/<(?:\\w+:)?nNFSe>\\s*([^<]+?)\\s*<\\/(?:\\w+:)?nNFSe>/u', $rawXml, $matches) !== 1) {
            return null;
        }

        $parsedNumber = trim((string) ($matches[1] ?? ''));

        return $parsedNumber !== '' ? $parsedNumber : null;
    }

    protected function monthNameByNumber(int $month): string
    {
        $months = [
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'marco',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
        ];

        return $months[$month] ?? 'mes-invalido';
    }

    protected function sanitizePathSegment(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^\pL\pN]+/u', '-', $normalized);
        $normalized = is_string($normalized) ? trim($normalized, '-') : '';

        return $normalized !== '' ? $normalized : 'nao-informado';
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
    protected function handlePostEmitEmail(?Request $request, Invoice $invoice, \Modules\Nfse\Models\NfseReceipt $receipt): void
    {
        if ($request === null) {
            return;
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
            return;
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
            return;
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

        $this->sendNfseIssuedNotification($invoice, $receipt, $attachDanfse, $attachXml, $customMail);
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
        $documentContactEmail = trim((string) ($invoice->contact_email ?? ''));

        $candidates = [
            $this->contactStringField($invoice->contact, ['email']),
            $documentContactEmail,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim($candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    protected function sendNfseIssuedNotification(Invoice $invoice, \Modules\Nfse\Models\NfseReceipt $receipt, bool $attachDanfse, bool $attachXml, array $customMail): void
    {
        $notifiable = $invoice->contact;

        if ($notifiable === null) {
            if (empty($customMail['to'])) {
                return;
            }

            \Illuminate\Support\Facades\Notification::route('mail', (string) $customMail['to'])
                ->notify(new \Modules\Nfse\Notifications\NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $customMail));

            return;
        }

        $notifiable->notify(new \Modules\Nfse\Notifications\NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $customMail));
    }

}
