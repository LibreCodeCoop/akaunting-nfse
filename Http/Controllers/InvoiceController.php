<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
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
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;

class InvoiceController extends Controller
{
    public function dashboard(): \Illuminate\View\View
    {
        $stats = $this->dashboardStats();

        return view('nfse::dashboard.index', compact('stats'));
    }

    public function index(?Request $request = null): \Illuminate\View\View
    {
        $request = $this->currentRequest($request);

        $search = $this->normalizedIndexSearch($request?->query('search', $request?->query('q')));
        $parsedFilters = $this->parsedIndexSearchFilters($search);
        $status = $parsedFilters['status'] ?? $this->normalizedIndexStatus($request?->query('status'));
        $perPage = $parsedFilters['per_page'] ?? $this->normalizedIndexPerPage($request?->query('limit', $request?->query('per_page')));
        $searchTerm = $parsedFilters['search'];
        $overviewCounts = $this->listingOverviewCounts();
        $receipts = $status === 'pending' ? null : $this->receiptsForIndex($status, $perPage, $searchTerm, $parsedFilters['date_emissao'] ?? null);
        $pendingInvoices = $status === 'pending' ? $this->pendingInvoices($perPage, $searchTerm) : null;
        $pendingReadiness = $status === 'pending' ? $this->emissionReadiness() : ['isReady' => true, 'checklist' => []];

        return view('nfse::invoices.index', compact('receipts', 'pendingInvoices', 'pendingReadiness', 'overviewCounts', 'status', 'perPage', 'search'));
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
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();

        return view('nfse::invoices.show', compact('invoice', 'receipt'));
    }

    public function emit(Invoice $invoice): RedirectResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $defaultService = $this->resolveDefaultCompanyService($invoice);

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = (bool) setting('nfse.sandbox_mode', true);
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues((float) $invoice->amount);

        $dps = $this->makeDpsData([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $ibge,
            'itemListaServico' => $this->itemListaServico($defaultService),
            'codigoTributacaoNacional' => $this->nationalTaxCode($defaultService),
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => $this->normalizedAliquota($defaultService),
            'discriminacao' => $this->buildDiscriminacao($invoice),
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

        $this->storeEmittedReceipt($invoice, $receipt);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_emitted', ['number' => $receipt->nfseNumber]));
    }

    public function cancel(Invoice $invoice, ?Request $request = null): RedirectResponse
    {
        $receipt = $this->findReceiptForInvoice($invoice);

        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));
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
        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));

        try {
            $updatedReceipt = $client->query($receipt->chave_acesso);

            $receipt->update([
                'nfse_number' => $updatedReceipt->nfseNumber,
                'chave_acesso' => $updatedReceipt->chaveAcesso,
                'data_emissao' => $updatedReceipt->dataEmissao,
                'codigo_verificacao' => $updatedReceipt->codigoVerificacao,
                'status' => 'emitted',
            ]);

            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('success', trans('nfse::general.nfse_refreshed', ['number' => $updatedReceipt->nfseNumber]));
        } catch (\Throwable) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_refresh_failed'));
        }
    }

    public function refreshAll(): RedirectResponse
    {
        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));
        $updated = 0;
        $failed = 0;

        foreach ($this->refreshableReceipts() as $receipt) {
            try {
                $updatedReceipt = $client->query($receipt->chave_acesso);

                $receipt->update([
                    'nfse_number' => $updatedReceipt->nfseNumber,
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

    public function reemit(Invoice $invoice): RedirectResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $defaultService = $this->resolveDefaultCompanyService($invoice);

        $receipt = $this->findReceiptForInvoice($invoice);

        if (($receipt->status ?? '') !== 'cancelled') {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.nfse_reemit_not_cancelled'));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $sandboxReemit = (bool) setting('nfse.sandbox_mode', true);
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $tomadorPayload = $this->tomadorPayload($invoice->contact);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues((float) $invoice->amount);

        $dps = $this->makeDpsData([
            'cnpjPrestador' => (string) setting('nfse.cnpj_prestador'),
            'municipioIbge' => (string) setting('nfse.municipio_ibge'),
            'itemListaServico' => $this->itemListaServico($defaultService),
            'codigoTributacaoNacional' => $this->nationalTaxCode($defaultService),
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
            'aliquota' => $this->normalizedAliquota($defaultService),
            'discriminacao' => $this->buildDiscriminacao($invoice),
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

        $this->storeEmittedReceipt($invoice, $newReceipt);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_reemitted', ['number' => $newReceipt->nfseNumber]));
    }

    // -------------------------------------------------------------------------

    protected function buildDiscriminacao(Invoice $invoice): string
    {
        return implode(' | ', $invoice->items->pluck('name')->toArray())
            ?: $invoice->description
            ?: trans('nfse::general.service_default');
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
            'sandbox_mode' => (bool) setting('nfse.sandbox_mode', true),
        ];
    }

    protected function receiptsForIndex(string $status, int $perPage, ?string $search, ?array $dateFilter = null): mixed
    {
        $query = NfseReceipt::with('invoice.contact');

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
                    ->orWhereHas('invoice.contact', function ($contactQuery) use ($search) {
                        $contactQuery->where('name', 'like', '%' . $search . '%');
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

        return $query->latest()->paginate($perPage);
    }

    /**
     * @return array{total: int, emitted: int, processing: int, cancelled: int, pending: int}
     */
    protected function listingOverviewCounts(): array
    {
        try {
            $totalReceipts = NfseReceipt::count();
        } catch (\Throwable) {
            $totalReceipts = 0;
        }

        try {
            $emitted = NfseReceipt::where('status', 'emitted')->count();
        } catch (\Throwable) {
            $emitted = 0;
        }

        try {
            $processing = NfseReceipt::where('status', 'processing')->count();
        } catch (\Throwable) {
            $processing = 0;
        }

        try {
            $cancelled = NfseReceipt::where('status', 'cancelled')->count();
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
        $allowed = ['all', 'emitted', 'cancelled', 'processing', 'pending'];

        return in_array($normalized, $allowed, true) ? $normalized : 'all';
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

        $normalized = trim($search);

        return $normalized !== '' ? $normalized : null;
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
        return $this->pendingInvoicesQuery($search)->latest()->paginate($perPage);
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

    protected function storeEmittedReceipt(Invoice $invoice, ReceiptData $receipt): void
    {
        NfseReceipt::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'nfse_number' => $receipt->nfseNumber,
                'chave_acesso' => $receipt->chaveAcesso,
                'data_emissao' => $receipt->dataEmissao,
                'codigo_verificacao' => $receipt->codigoVerificacao,
                'status' => 'emitted',
            ]
        );
    }

    protected function findReceiptForInvoice(Invoice $invoice): NfseReceipt
    {
        return NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();
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
