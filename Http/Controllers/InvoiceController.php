<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Common\Contact;
use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
use Modules\Nfse\Models\CompanyService;
use Modules\Nfse\Models\ItemServiceMapping;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\WebDavClient;

class InvoiceController extends Controller
{
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
        $suggestedDiscriminacao = $this->buildDiscriminacao($invoice);

        return view('nfse::invoices.show', compact('invoice', 'receipt', 'suggestedDiscriminacao'));
    }

    public function servicePreview(Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $defaultService = $this->resolveDefaultCompanyService($invoice);
        $selection = $this->resolveInvoiceServiceSelection($invoice, $defaultService, null, false);

        return response()->json([
            'missing_items' => $selection['missing_items'],
            'available_services' => $this->availableInvoiceServices($invoice),
            'default_service_id' => is_object($defaultService) ? (int) ($defaultService->id ?? 0) : 0,
            'requires_split' => (bool) ($selection['requires_split'] ?? false),
            'suggested_description' => $this->buildDiscriminacao($invoice, $selection['line_items'] ?? []),
        ]);
    }

    public function emit(Invoice $invoice, ?Request $request = null): RedirectResponse
    {
        $request = $this->currentRequest($request);
        $this->ensureInvoiceRelationsLoaded($invoice);
        $defaultService = $this->resolveDefaultCompanyService($invoice);
        $selection = $this->resolveInvoiceServiceSelection($invoice, $defaultService, $request, true);
        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);

        if (($selection['requires_split'] ?? false) === true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('warning', trans('nfse::general.invoices.mixed_service_tax_profiles_not_supported'));
        }

        if (($selection['requires_confirmation'] ?? false) === true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('warning', trans('nfse::general.invoices.default_service_confirmation_required'));
        }

        $serviceForIssuance = $selection['selected_service'] ?? $defaultService;

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = $this->sandboxModeEnabled();
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues((float) $invoice->amount);

        $dps = $this->makeDpsData([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $ibge,
            'itemListaServico' => $this->itemListaServico($serviceForIssuance),
            'codigoTributacaoNacional' => $this->nationalTaxCode($serviceForIssuance),
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => $this->normalizedAliquota($serviceForIssuance),
            'discriminacao' => $this->buildDiscriminacao($invoice, $selection['line_items'] ?? [], $customDiscriminacao),
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
            'tributos_fed_p' => (string) setting('nfse.tributos_fed_p', ''),
            'tributos_est_p' => (string) setting('nfse.tributos_est_p', ''),
            'tributos_mun_p' => (string) setting('nfse.tributos_mun_p', ''),
        ]);

        $client = $this->makeClient($sandbox);

        try {
            $receipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
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

            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail);
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e issuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed'));
        } catch (PfxImportException) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_pfx_import_failed'));
        }

        $persistedReceipt = $this->storeEmittedReceipt($invoice, $receipt);
        $this->storeArtifacts($invoice, $receipt, $persistedReceipt, $client);
        $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($receipt);

        $taxPolicyMessage = $this->canonicalTaxPolicyMessage($invoice);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_emitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $receipt->chaveAcesso]))
            ->with('info', $taxPolicyMessage);
    }

    public function cancel(Invoice $invoice, ?Request $request = null): RedirectResponse
    {
        $receipt = $this->findReceiptForInvoice($invoice);

        $client = $this->makeClient($this->sandboxModeEnabled());
        $cancelReason = $this->cancellationReasonForGateway($request);

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

                return redirect()->route('nfse.invoices.index')
                    ->with('success', trans('nfse::general.nfse_cancelled'));
            }

            $this->safeLogError('NFS-e cancellation rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
            ]);

            return redirect()->route('nfse.invoices.index')
                ->with('error', trans('nfse::general.nfse_cancel_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail);
        }

        $receipt->update(['status' => 'cancelled']);

        return redirect()->route('nfse.invoices.index')
            ->with('success', trans('nfse::general.nfse_cancelled'));
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

    public function reemit(Invoice $invoice, ?Request $request = null): RedirectResponse
    {
        $request = $this->currentRequest($request);
        $this->ensureInvoiceRelationsLoaded($invoice);
        $defaultService = $this->resolveDefaultCompanyService($invoice);
        $selection = $this->resolveInvoiceServiceSelection($invoice, $defaultService, $request, true);
        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);

        $receipt = $this->findReceiptForInvoice($invoice);

        if (($receipt->status ?? '') !== 'cancelled') {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.nfse_reemit_not_cancelled'));
        }

        if (($selection['requires_split'] ?? false) === true) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.mixed_service_tax_profiles_not_supported'));
        }

        if (($selection['requires_confirmation'] ?? false) === true) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.invoices.default_service_confirmation_required'));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $sandboxReemit = $this->sandboxModeEnabled();
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues((float) $invoice->amount);
        $serviceForIssuance = $selection['selected_service'] ?? $defaultService;

        $dps = $this->makeDpsData([
            'cnpjPrestador' => (string) setting('nfse.cnpj_prestador'),
            'municipioIbge' => (string) setting('nfse.municipio_ibge'),
            'itemListaServico' => $this->itemListaServico($serviceForIssuance),
            'codigoTributacaoNacional' => $this->nationalTaxCode($serviceForIssuance),
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => $this->normalizedAliquota($serviceForIssuance),
            'discriminacao' => $this->buildDiscriminacao($invoice, $selection['line_items'] ?? [], $customDiscriminacao),
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
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
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

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail);
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e reissuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed'));
        } catch (PfxImportException) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed'));
        }

        $persistedReceipt = $this->storeEmittedReceipt($invoice, $newReceipt, $receipt);
        $this->storeArtifacts($invoice, $newReceipt, $persistedReceipt, $client);
        $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($newReceipt);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_reemitted', ['number' => $resolvedReceiptNumber !== '' ? $resolvedReceiptNumber : $newReceipt->chaveAcesso]));
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

        if ($lineItems !== []) {
            return implode(' | ', $lineItems);
        }

        return implode(' | ', $invoice->items->pluck('name')->toArray())
            ?: $invoice->description
            ?: trans('nfse::general.service_default');
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

        $normalized = preg_replace('/\s+/', ' ', trim($rawValue));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    /**
    * @return array{selected_service: ?object, line_items: list<string>, missing_items: list<array{id:int,name:string}>, requires_confirmation: bool, requires_split: bool}
     */
    protected function resolveInvoiceServiceSelection(Invoice $invoice, ?object $defaultService, ?Request $request = null, bool $persistAssignments = false): array
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

        if ($itemIds === [] || ! $this->supportsItemServiceMapping()) {
            return [
                'selected_service' => $defaultService,
                'line_items' => [],
                'missing_items' => [],
                'requires_confirmation' => false,
                'requires_split' => false,
            ];
        }

        $companyId = is_numeric($invoice->company_id ?? null) ? (int) $invoice->company_id : $this->resolveCompanyId();

        if ($companyId <= 0) {
            return [
                'selected_service' => $defaultService,
                'line_items' => [],
                'missing_items' => [],
                'requires_confirmation' => false,
                'requires_split' => false,
            ];
        }

        if ($persistAssignments && $request instanceof Request) {
            $this->persistInvoiceItemAssignmentsFromRequest($companyId, $request);
        }

        $serviceMap = $this->invoiceItemServiceMap($companyId, $itemIds);
        $lineItems = [];
        $missingItems = [];
        $selectedService = null;
        $appliedServiceProfiles = [];

        $registerServiceProfile = static function (?object $service) use (&$appliedServiceProfiles): void {
            if ($service === null) {
                return;
            }

            $serviceId = is_numeric($service->id ?? null) ? (int) $service->id : 0;

            if ($serviceId > 0) {
                $appliedServiceProfiles['id:' . $serviceId] = true;

                return;
            }

            $serviceCode = preg_replace('/\D+/', '', (string) ($service->item_lista_servico ?? '')) ?: '';
            $serviceAliquota = str_replace(',', '.', trim((string) ($service->aliquota ?? '')));

            $appliedServiceProfiles['fallback:' . $serviceCode . ':' . $serviceAliquota] = true;
        };

        foreach ($items as $item) {
            $itemName = trim((string) ($item['name'] ?? ''));
            $itemName = $itemName !== '' ? $itemName : trans('general.na');
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            $mappedService = $itemId > 0 ? ($serviceMap[$itemId] ?? null) : null;

            if ($mappedService !== null) {
                if ($selectedService === null) {
                    $selectedService = $mappedService;
                }

                $registerServiceProfile($mappedService);
                $lineItems[] = '[' . $this->itemListaServico($mappedService) . '] ' . $itemName;
                continue;
            }

            if ($itemId > 0) {
                $missingItems[] = ['id' => $itemId, 'name' => $itemName];
            }

            if ($defaultService !== null) {
                if ($selectedService === null) {
                    $selectedService = $defaultService;
                }

                $registerServiceProfile($defaultService);
                $lineItems[] = '[' . $this->itemListaServico($defaultService) . '] ' . $itemName;
                continue;
            }

            $lineItems[] = $itemName;
        }

        $confirmedFallback = $request instanceof Request
            && (string) $request->input('nfse_confirm_default_service', '0') === '1';
        $requiresConfirmation = $missingItems !== [] && $defaultService !== null && !$confirmedFallback;
        $requiresSplit = count($appliedServiceProfiles) > 1;

        return [
            'selected_service' => $selectedService ?? $defaultService,
            'line_items' => $lineItems,
            'missing_items' => $missingItems,
            'requires_confirmation' => $requiresConfirmation,
            'requires_split' => $requiresSplit,
        ];
    }

    /**
     * @return list<array{id:int,label:string,is_default:bool}>
     */
    protected function availableInvoiceServices(Invoice $invoice): array
    {
        if (! $this->supportsCompanyServiceSelection()) {
            return [];
        }

        $companyId = is_numeric($invoice->company_id ?? null) ? (int) $invoice->company_id : $this->resolveCompanyId();

        if ($companyId <= 0) {
            return [];
        }

        try {
            return CompanyService::where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('item_lista_servico')
                ->get()
                ->map(function ($service): array {
                    $displayName = trim((string) ($service->display_name ?? ''));
                    $description = trim((string) ($service->description ?? ''));

                    $label = $displayName;
                    if ($description !== '') {
                        $label .= ' - ' . $description;
                    }

                    return [
                        'id' => (int) $service->id,
                        'label' => $label,
                        'is_default' => (bool) ($service->is_default ?? false),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
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

    protected function supportsItemServiceMapping(): bool
    {
        if (!class_exists(\Illuminate\Database\Eloquent\Model::class)) {
            return false;
        }

        return is_subclass_of(ItemServiceMapping::class, \Illuminate\Database\Eloquent\Model::class)
            && $this->supportsCompanyServiceSelection();
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, object>
     */
    protected function invoiceItemServiceMap(int $companyId, array $itemIds): array
    {
        if ($itemIds === [] || ! $this->supportsItemServiceMapping()) {
            return [];
        }

        try {
            $mappings = ItemServiceMapping::where('company_id', $companyId)
                ->whereIn('item_id', $itemIds)
                ->get();

            $serviceIds = $mappings
                ->pluck('company_service_id')
                ->map(static fn ($value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value > 0)
                ->unique()
                ->values()
                ->all();

            if ($serviceIds === []) {
                return [];
            }

            $services = CompanyService::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('id', $serviceIds)
                ->get()
                ->keyBy('id');

            $map = [];

            foreach ($mappings as $mapping) {
                $itemId = (int) ($mapping->item_id ?? 0);
                $serviceId = (int) ($mapping->company_service_id ?? 0);

                if ($itemId <= 0 || $serviceId <= 0 || !isset($services[$serviceId])) {
                    continue;
                }

                $map[$itemId] = $services[$serviceId];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function persistInvoiceItemAssignmentsFromRequest(int $companyId, Request $request): void
    {
        if (! $this->supportsItemServiceMapping()) {
            return;
        }

        $raw = $request->input('nfse_item_service_assignments', '');

        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return;
        }

        $itemIds = [];
        $serviceIds = [];

        foreach ($decoded as $itemId => $serviceId) {
            if (!is_numeric($itemId) || !is_numeric($serviceId)) {
                continue;
            }

            $itemId = (int) $itemId;
            $serviceId = (int) $serviceId;

            if ($itemId <= 0 || $serviceId <= 0) {
                continue;
            }

            $itemIds[] = $itemId;
            $serviceIds[] = $serviceId;
        }

        if ($itemIds === [] || $serviceIds === []) {
            return;
        }

        try {
            $validServiceIds = CompanyService::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('id', array_values(array_unique($serviceIds)))
                ->pluck('id')
                ->map(static fn ($value): int => (int) $value)
                ->all();

            foreach ($decoded as $itemId => $serviceId) {
                if (!is_numeric($itemId) || !is_numeric($serviceId)) {
                    continue;
                }

                $itemId = (int) $itemId;
                $serviceId = (int) $serviceId;

                if ($itemId <= 0 || $serviceId <= 0 || !in_array($serviceId, $validServiceIds, true)) {
                    continue;
                }

                ItemServiceMapping::updateOrCreate(
                    ['company_id' => $companyId, 'item_id' => $itemId],
                    ['company_service_id' => $serviceId],
                );
            }
        } catch (\Throwable) {
            // Keep issuance flow resilient when persistence fails.
        }
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
            $invoice->loadMissing(['contact', 'items']);
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
            'total' => $totalReceipts + $pending,
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
        // Never auto-restore non-default filters from bare URL; this prevents
        // stale search/status values from returning right after user clears filters.
        $status = $preferences['status'] ?? null;
        $search = $preferences['search'] ?? null;
        $perPage = (int) ($preferences['per_page'] ?? 25);
        $sortBy = (string) ($preferences['sort_by'] ?? 'due_at');
        $sortDirection = (string) ($preferences['sort_direction'] ?? 'desc');

        $hasNonDefaultStatus = $status !== null && $status !== 'all';
        $hasSearch = is_string($search) && trim($search) !== '';
        $hasNeutralOverrides = $perPage !== 25 || $sortBy !== 'due_at' || $sortDirection !== 'desc';

        return !$hasNonDefaultStatus && !$hasSearch && $hasNeutralOverrides;
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
        try {
            $processedInvoiceIds = NfseReceipt::query()
                ->pluck('invoice_id')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            $processedInvoiceIds = [];
        }

        $query = Invoice::invoice()
            ->with(['contact'])
            ->whereHas('contact', static fn ($contactQuery) => $contactQuery->where('type', Contact::CUSTOMER_TYPE))
            ->when(
                $processedInvoiceIds !== [],
                static fn ($query) => $query->whereNotIn('id', $processedInvoiceIds)
            );

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
        $defaultService = $this->resolveDefaultCompanyService();
        $cnpj = (string) ($settings['cnpj_prestador'] ?? '');
        $certificatePath = $cnpj !== '' ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx') : '';
        $transportCertificatePath = $this->projectRootPath('client.crt.pem');
        $transportPrivateKeyPath = $this->projectRootPath('client.key.pem');

        $checklist = [
            'cnpj_prestador' => $cnpj !== '',
            'municipio_ibge' => ((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => $this->itemListaServico($defaultService) !== '',
            'certificate' => $certificatePath !== '' && is_file($certificatePath),
            'certificate_secret' => $this->hasCertificateSecret($cnpj),
            'transport_certificate' => is_file($transportCertificatePath) && is_file($transportPrivateKeyPath),
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

    protected function resolveDefaultCompanyService(?Invoice $invoice = null): ?object
    {
        if (! $this->supportsCompanyServiceSelection()) {
            return null;
        }

        $companyId = is_numeric($invoice?->company_id ?? null) ? (int) $invoice->company_id : $this->resolveCompanyId();

        if ($companyId <= 0) {
            return null;
        }

        return CompanyService::where('company_id', $companyId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    protected function supportsCompanyServiceSelection(): bool
    {
        if (! class_exists(\Illuminate\Database\Eloquent\Model::class)) {
            return false;
        }

        return class_exists(CompanyService::class)
            && is_subclass_of(CompanyService::class, \Illuminate\Database\Eloquent\Model::class);
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

    protected function federalPayloadValues(float $invoiceAmount): array
    {
        $situacaoTributaria = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_situacao_tributaria', ''));
        $tipoRetencao = $this->normalizedFederalSelectValue(setting('nfse.federal_piscofins_tipo_retencao', ''));
        $valorCsllRetencao = $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_csll');

        if (in_array($tipoRetencao, ['4', '5', '6'], true) && $valorCsllRetencao === '') {
            // Gateway currently rejects tpRetPisCofins != 0 without vRetCSLL.
            // When configured CSLL retention is zero, fallback avoids invalid payloads.
            $tipoRetencao = '0';
        }

        $isSimplesNacionalOptant = $this->normalizedOpcaoSimplesNacional() === 2;

        $totalTributosPercentualFederal = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_fed_sn' : 'nfse.tributos_fed_p', ''));
        $totalTributosPercentualEstadual = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_est_sn' : 'nfse.tributos_est_p', ''));
        $totalTributosPercentualMunicipal = $this->normalizedFederalDecimal(setting($isSimplesNacionalOptant ? 'nfse.tributos_mun_sn' : 'nfse.tributos_mun_p', ''));

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

        if ($situacaoTributaria === '' || $situacaoTributaria === '0') {
            return [
                'federalPiscofinsSituacaoTributaria' => '',
                'federalPiscofinsTipoRetencao' => '',
                'federalPiscofinsBaseCalculo' => '',
                'federalPiscofinsAliquotaPis' => '',
                'federalPiscofinsValorPis' => '',
                'federalPiscofinsAliquotaCofins' => '',
                'federalPiscofinsValorCofins' => '',
                'federalValorIrrf' => $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_irrf'),
                'federalValorCsll' => $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_csll'),
                // Produção restrita currently rejects vRetCP (RNG6110), so keep CP as UI/config only.
                'federalValorCp' => '',
                'indicadorTributacao' => $indicadorTributacao,
                'totalTributosPercentualFederal' => $totalTributosPercentualFederal,
                'totalTributosPercentualEstadual' => $totalTributosPercentualEstadual,
                'totalTributosPercentualMunicipal' => $totalTributosPercentualMunicipal,
            ];
        }

        $aliquotaPis = $this->normalizedFederalDecimal(setting('nfse.federal_piscofins_aliquota_pis', ''));
        $aliquotaCofins = $this->normalizedFederalDecimal(setting('nfse.federal_piscofins_aliquota_cofins', ''));

        return [
            'federalPiscofinsSituacaoTributaria' => $situacaoTributaria,
            'federalPiscofinsTipoRetencao' => $tipoRetencao,
            'federalPiscofinsBaseCalculo' => number_format($invoiceAmount, 2, '.', ''),
            'federalPiscofinsAliquotaPis' => $aliquotaPis,
            'federalPiscofinsValorPis' => $aliquotaPis !== ''
                ? number_format($invoiceAmount * (float) $aliquotaPis / 100, 2, '.', '')
                : '',
            'federalPiscofinsAliquotaCofins' => $aliquotaCofins,
            'federalPiscofinsValorCofins' => $aliquotaCofins !== ''
                ? number_format($invoiceAmount * (float) $aliquotaCofins / 100, 2, '.', '')
                : '',
            'federalValorIrrf' => $this->calculateFederalRetentionValue($invoiceAmount, 'federal_valor_irrf'),
            'federalValorCsll' => $tipoRetencao !== '0'
                ? $valorCsllRetencao
                : '',
            // Produção restrita currently rejects vRetCP (RNG6110), so keep CP as UI/config only.
            'federalValorCp' => '',
            'indicadorTributacao' => $indicadorTributacao,
            'totalTributosPercentualFederal' => $totalTributosPercentualFederal,
            'totalTributosPercentualEstadual' => $totalTributosPercentualEstadual,
            'totalTributosPercentualMunicipal' => $totalTributosPercentualMunicipal,
        ];
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
}
