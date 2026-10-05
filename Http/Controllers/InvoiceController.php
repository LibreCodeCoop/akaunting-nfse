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
use Modules\Nfse\Application\FederalTaxSnapshotBuilder;
use Modules\Nfse\Application\FiscalProfileEmissionReadiness;
use Modules\Nfse\Application\InvoiceFiscalGroupBuilder;
use Modules\Nfse\Application\InvoiceFiscalProfileSelector;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Application\IssueInvoiceNfse;
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
use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Support\OperationalReadinessResolver;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
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
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $receiptStatusLabel = $this->translateReceiptStatus((string) ($receipt->status ?? ''));
        $suggestedDiscriminacao = $this->buildDiscriminacao($invoice);
        $emailDefaults = $this->servicePreviewEmailDefaults($invoice);
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        return view('nfse::invoices.show', compact('invoice', 'receipt', 'receiptStatusLabel', 'suggestedDiscriminacao', 'emailDefaults', 'artifacts'));
    }

    public function showEmitSuccess(Invoice $invoice): \Illuminate\View\View
    {
        $this->ensureInvoiceRelationsLoaded($invoice);
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $receiptStatusLabel = $this->translateReceiptStatus((string) ($receipt->status ?? ''));
        $artifacts = $this->resolveReceiptArtifacts($invoice, $receipt);

        return view('nfse::invoices.partials.emit-success', compact('invoice', 'receipt', 'receiptStatusLabel', 'artifacts'));
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
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_no_items')));
        }

        $customDiscriminacao = $this->customDiscriminacaoFromRequest($request);
        $this->persistDefaultDescriptionFromRequest($request);

        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        $fiscalProfileReadiness = (new FiscalProfileEmissionReadiness())->evaluate($itemFiscalProfile);

        if (($fiscalProfileReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                    ->with('error', $this->invalidFiscalProfileMessage($fiscalProfileReadiness)),
            );
        }

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
        $federalTaxReadiness = $this->federalTaxReadinessForInvoice(
            $invoice,
            $selectedDocumentItemIds,
            $selectedFiscalAmount,
        );

        if (($federalTaxReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', $this->emitBlockedFederalTaxMessage($federalTaxReadiness['missing'] ?? [])));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
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
                redirect()->route('nfse.invoices.index', ['status' => 'pending'])
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
            $selectedFiscalAmount,
        );
        $ibsCbsPayload = $this->ibsCbsPayloadValues();
        $issqnPayload = $this->issqnPayloadValues();

        $requiredDpsFields = $foreignTomador['enabled']
            ? [
                'tomadorNif',
                'tomadorCodigoNaoNif',
                'tomadorPaisCodigo',
                'tomadorCodigoPostalExterior',
                'tomadorCidadeExterior',
                'tomadorEstadoExterior',
            ]
            : [];

        $requiredDpsFields[] = 'codigoTributacaoMunicipal';

        if ($issqnPayload['requiresSpecialRuntime']) {
            $requiredDpsFields = array_values(array_unique(array_merge($requiredDpsFields, [
                'tributacaoIssqn',
                'issqnPaisResultado',
                'issqnTipoImunidade',
                'issqnTipoSuspensao',
                'issqnNumeroProcessoSuspensao',
                'tipoRetencaoIss',
            ])));
        }

        try {
            $dps = $this->makeDpsData([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $ibge,
            'itemListaServico' => (string) $itemFiscalProfile['item_lista_servico'],
            'codigoTributacaoNacional' => (string) $itemFiscalProfile['codigo_tributacao_nacional'],
            'codigoTributacaoMunicipal' => '',
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
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
            'tomadorNif' => $foreignTomador['enabled'] ? $foreignTomador['nif'] : '',
            'tomadorCodigoNaoNif' => $foreignTomador['enabled'] ? $foreignTomador['codigo_nao_nif'] : null,
            'tomadorPaisCodigo' => $foreignTomador['enabled'] ? $foreignTomador['pais_codigo'] : '',
            'tomadorCodigoPostalExterior' => $foreignTomador['enabled'] ? $foreignTomador['codigo_postal'] : '',
            'tomadorCidadeExterior' => $foreignTomador['enabled'] ? $foreignTomador['cidade'] : '',
            'tomadorEstadoExterior' => $foreignTomador['enabled'] ? $foreignTomador['estado'] : '',
            'opcaoSimplesNacional' => $opcaoSimplesNacional,
            'tributacaoIssqn' => $issqnPayload['tributacaoIssqn'],
            'issqnPaisResultado' => $issqnPayload['issqnPaisResultado'],
            'issqnTipoImunidade' => $issqnPayload['issqnTipoImunidade'],
            'issqnTipoSuspensao' => $issqnPayload['issqnTipoSuspensao'],
            'issqnNumeroProcessoSuspensao' => $issqnPayload['issqnNumeroProcessoSuspensao'],
            'tipoRetencaoIss' => $issqnPayload['tipoRetencaoIss'],
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
            'ibsCbsFinalidade' => $ibsCbsPayload['ibsCbsFinalidade'],
            'ibsCbsIndFinal' => $ibsCbsPayload['ibsCbsIndFinal'],
            'ibsCbsCodigoIndicadorOperacao' => $ibsCbsPayload['ibsCbsCodigoIndicadorOperacao'],
            'ibsCbsIndDest' => $ibsCbsPayload['ibsCbsIndDest'],
            'ibsCbsCst' => $ibsCbsPayload['ibsCbsCst'],
            'ibsCbsClassificacaoTributaria' => $ibsCbsPayload['ibsCbsClassificacaoTributaria'],
            ], $requiredDpsFields);
        } catch (\LogicException $e) {
            $this->safeLogError('NFS-e runtime capability mismatch', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.index', ['status' => 'pending'])
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
                    $this->storeArtifacts(
                        $invoice,
                        $groupResult['remote_receipt'],
                        $groupResult['receipt'],
                        $client,
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
                        ->with('nfse_gateway_error_detail', $this->gatewayErrorDetail($e)),
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
            $receipt = $substitutionReceipt instanceof NfseReceipt
                ? (new SubstituteInvoiceNfse())->issue(
                    client: $client,
                    replacementDps: $dps,
                    originalAccessKey: (string) $substitutionReceipt->chave_acesso,
                )
                : (new IssueInvoiceNfse())->issue($client, $dps);
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
        } catch (\JsonException $e) {
            $this->safeLogError('NFS-e issuance failed due invalid non-JSON gateway response', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed')));
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e issuance failed after DPS recovery could not resolve the ambiguous outcome', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
                'dps_recovery_supported' => isset($client) && is_callable([$client, 'queryDps']),
            ]);

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_emit_failed')));
        } catch (PfxImportException) {
            $this->cleanupClientTransportArtifacts();

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        try {
            $persistedReceipt = $substitutionReceipt instanceof NfseReceipt
                ? (new ReceiptPersistence())->createReplacement(
                    invoiceId: (int) $invoice->id,
                    receipt: $receipt,
                    resolvedNumber: $this->resolveReceiptNfseNumber($receipt),
                    original: $substitutionReceipt,
                )
                : $this->storeEmittedReceipt($invoice, $receipt);

            $this->storeArtifacts($invoice, $receipt, $persistedReceipt, $client);
            $this->markInvoiceSentAfterEmission($invoice);
            $this->handlePostEmitEmail($request, $invoice, $persistedReceipt);
            $resolvedReceiptNumber = $this->resolveReceiptNfseNumber($receipt);
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

        $cancelReason = $this->cancellationReasonForGateway($request);
        $redirect = $this->cancellationRedirect($invoice, $request);

        try {
            $client = $this->makeClient($this->sandboxModeEnabled());
            (new CancelInvoiceNfse())->cancel($client, $receipt, $cancelReason);
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

        try {
            $client = $this->makeClient($this->sandboxModeEnabled());
            $updatedReceipt = (new RefreshInvoiceNfse())->refresh($client, $receipt);
            $resolvedReceiptNumber = (new ReceiptNumberResolver())->resolve($updatedReceipt);

            try {
                $this->storeArtifacts($invoice, $updatedReceipt, $receipt, $client);
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
        } catch (\Throwable) {
            $this->cleanupClientTransportArtifacts();

            return redirect()->route('nfse.invoices.show', $invoice)
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
            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.index', ['status' => 'pending'])
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready')));
        }

        $sandboxReemit = $this->sandboxModeEnabled();
        $tomadorDocument = $this->resolvedTomadorDocument($invoice);
        $tomadorPayload = $this->tomadorPayload($invoice->contact, $invoice);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();
        $federalPayload = $this->federalPayloadValues($invoice);
        $itemFiscalProfile = $this->resolveInvoiceFiscalProfileFromItems($invoice);
        $fiscalProfileReadiness = (new FiscalProfileEmissionReadiness())->evaluate($itemFiscalProfile);

        if (($fiscalProfileReadiness['isReady'] ?? false) !== true) {
            return $this->ajaxAwareRedirect(
                $request,
                redirect()->route('nfse.invoices.show', $invoice)
                    ->with('error', $this->invalidFiscalProfileMessage($fiscalProfileReadiness)),
            );
        }

        try {
            $dps = $this->makeDpsData([
            'cnpjPrestador' => (string) setting('nfse.cnpj_prestador'),
            'municipioIbge' => (string) setting('nfse.municipio_ibge'),
            'itemListaServico' => (string) $itemFiscalProfile['item_lista_servico'],
            'codigoTributacaoNacional' => (string) $itemFiscalProfile['codigo_tributacao_nacional'],
            'codigoTributacaoMunicipal' => '',
            'valorServico' => number_format((float) $invoice->amount, 2, '.', ''),
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
            ], ['codigoTributacaoMunicipal']);
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
            $this->cleanupClientTransportArtifacts();

            return $this->ajaxAwareRedirect($request, redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_pfx_import_failed')));
        }

        try {
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
    protected function annotateFiscalGroupsWithReceiptState(Invoice $invoice, array $groups): array
    {
        $persistence = new ReceiptPersistence();

        foreach ($groups as &$group) {
            $key = (string) ($group['key'] ?? '');
            try {
                $receipt = $key !== ''
                    ? $persistence->findGrouped((int) $invoice->id, $key)
                    : null;
            } catch (\Throwable) {
                $receipt = null;
            }

            $group['issued'] = $receipt instanceof NfseReceipt;
            $group['receipt_id'] = $receipt?->id;
            $group['nfse_number'] = $receipt?->nfse_number;
        }
        unset($group);

        return $groups;
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    protected function remainingFiscalGroups(Invoice $invoice, array $groups): array
    {
        return array_values(array_filter(
            $this->annotateFiscalGroupsWithReceiptState($invoice, $groups),
            static fn (array $group): bool => ($group['issued'] ?? false) !== true,
        ));
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
                            'item_lista_servico' => Lc116Code::normalize($profile->item_lista_servico ?? ''),
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
    /**
     * @param list<int>|null $documentItemIds
     * @return array{isReady: bool, missing: list<string>}
     */
    protected function federalTaxReadinessForInvoice(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): array {
        $requiredBuckets = $this->requiredFederalTaxBucketsForEmission();

        if ($requiredBuckets === []) {
            return [
                'isReady' => true,
                'missing' => [],
            ];
        }

        $snapshot = $this->invoiceFederalTaxSnapshot(
            $invoice,
            $amountOverride ?? (float) ($invoice->amount ?? 0.0),
            $documentItemIds,
        );
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
        return $this->normalizedTomadorDocument(
            $this->contactOrInvoiceStringField($invoice->contact, $invoice, ['tax_number'], ['contact_tax_number'])
        );
    }

    protected function resolvedTomadorName(Invoice $invoice): string
    {
        return $this->contactOrInvoiceStringField($invoice->contact, $invoice, ['name'], ['contact_name']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $requiredFields
     */
    protected function makeDpsData(array $payload, array $requiredFields = []): DpsData
    {
        return (new RuntimeDpsFactory())->make($payload, $requiredFields);
    }

    /**
     * @param array{issues?:list<string>,source_versions?:array<string,string>} $readiness
     */
    protected function invalidFiscalProfileMessage(array $readiness): string
    {
        $issues = array_map(
            static fn (string $issue): string => (string) trans('nfse::general.items.validation.' . $issue),
            is_array($readiness['issues'] ?? null) ? $readiness['issues'] : [],
        );
        $versions = is_array($readiness['source_versions'] ?? null)
            ? array_values(array_filter($readiness['source_versions'], 'is_string'))
            : [];

        return (string) trans('nfse::general.invoices.emit_blocked_invalid_fiscal_profile', [
            'issues' => $issues !== [] ? implode('; ', $issues) : trans('nfse::general.items.validation.status_invalid'),
            'version' => $versions !== [] ? implode(', ', array_unique($versions)) : 'unknown',
        ]);
    }

    /**
     * @return array{codigo_municipio: string, cep: string, logradouro: string, numero: string, complemento: string, bairro: string, inscricao_municipal: string, telefone: string, email: string}
     */
    protected function tomadorPayload(?object $contact, ?object $invoice = null): array
    {
        $codigoMunicipio = $this->normalizedTomadorMunicipioIbge($contact, $invoice);
        $cep = $this->normalizedTomadorCep($this->contactOrInvoiceStringField($contact, $invoice, ['zip_code', 'cep'], ['contact_zip_code']));

        $logradouro = '';
        $numero = '';
        $complemento = '';
        $bairro = '';

        if ($codigoMunicipio !== '' && $cep !== '') {
            $logradouro = $this->contactOrInvoiceStringField($contact, $invoice, ['address', 'logradouro'], ['contact_address']);
            $numero = $this->contactOrInvoiceStringField($contact, $invoice, ['number', 'numero'], ['contact_number']);
            $complemento = $this->contactOrInvoiceStringField($contact, $invoice, ['complement', 'complemento'], ['contact_complement']);
            $bairro = $this->contactOrInvoiceStringField($contact, $invoice, ['district', 'bairro', 'neighborhood'], ['contact_district', 'contact_neighborhood']);
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
            'inscricao_municipal' => $this->contactOrInvoiceStringField($contact, $invoice, ['inscricao_municipal', 'municipal_registration', 'im'], ['contact_inscricao_municipal', 'contact_municipal_registration', 'contact_im']),
            'telefone' => $this->normalizedTomadorTelefone($this->contactOrInvoiceStringField($contact, $invoice, ['phone', 'telefone'], ['contact_phone'])),
            'email' => $this->normalizedTomadorEmail($this->contactOrInvoiceStringField($contact, $invoice, ['email'], ['contact_email'])),
        ];
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
            serviceCode: $this->itemListaServico(),
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
        $tributacao = (int) setting('nfse.tributacao_issqn', 1);
        if (!in_array($tributacao, [1, 2, 3, 4], true)) {
            $tributacao = 1;
        }

        $tipoRetencao = (int) setting('nfse.tipo_retencao_iss', 1);
        if (!in_array($tipoRetencao, [1, 2, 3], true)) {
            $tipoRetencao = 1;
        }

        $paisResultado = $tributacao === 3
            ? strtoupper(trim((string) setting('nfse.issqn_pais_resultado', '')))
            : '';

        $tipoImunidadeRaw = trim((string) setting('nfse.issqn_tipo_imunidade', ''));
        $tipoImunidade = $tributacao === 2 && in_array($tipoImunidadeRaw, ['1', '2', '3', '4', '5'], true)
            ? (int) $tipoImunidadeRaw
            : null;

        $tipoSuspensaoRaw = trim((string) setting('nfse.issqn_tipo_suspensao', ''));
        $tipoSuspensao = $tributacao === 1 && in_array($tipoSuspensaoRaw, ['1', '2'], true)
            ? (int) $tipoSuspensaoRaw
            : null;

        $numeroProcesso = $tributacao === 1
            ? trim((string) setting('nfse.issqn_numero_processo_suspensao', ''))
            : '';

        return [
            'tributacaoIssqn' => $tributacao,
            'issqnPaisResultado' => $paisResultado,
            'issqnTipoImunidade' => $tipoImunidade,
            'issqnTipoSuspensao' => $tipoSuspensao,
            'issqnNumeroProcessoSuspensao' => $numeroProcesso,
            'tipoRetencaoIss' => $tipoRetencao,
            'requiresSpecialRuntime' => $tributacao !== 1
                || $tipoRetencao !== 1
                || $tipoSuspensao !== null
                || $numeroProcesso !== '',
        ];
    }

    protected function ibsCbsPayloadValues(): array
    {
        $enabled = $this->booleanSetting('nfse.ibs_cbs_enabled', false);

        if (!$enabled) {
            return [
                'enabled' => false,
                'ibsCbsFinalidade' => null,
                'ibsCbsIndFinal' => null,
                'ibsCbsCodigoIndicadorOperacao' => '',
                'ibsCbsIndDest' => null,
                'ibsCbsCst' => '',
                'ibsCbsClassificacaoTributaria' => '',
            ];
        }

        $indFinal = trim((string) setting('nfse.ibs_cbs_ind_final', ''));
        $indDest = trim((string) setting('nfse.ibs_cbs_ind_dest', ''));

        return [
            'enabled' => true,
            'ibsCbsFinalidade' => 0,
            'ibsCbsIndFinal' => in_array($indFinal, ['0', '1'], true) ? (int) $indFinal : null,
            'ibsCbsCodigoIndicadorOperacao' => trim((string) setting('nfse.ibs_cbs_c_ind_op', '')),
            'ibsCbsIndDest' => in_array($indDest, ['0', '1'], true) ? (int) $indDest : null,
            'ibsCbsCst' => trim((string) setting('nfse.ibs_cbs_cst', '')),
            'ibsCbsClassificacaoTributaria' => trim((string) setting('nfse.ibs_cbs_c_class_trib', '')),
        ];
    }

    /**
     * @param list<int>|null $documentItemIds
     */
    protected function federalPayloadValues(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): array {
        $invoiceAmount = $amountOverride ?? (float) ($invoice->amount ?? 0.0);
        $federalMode = strtolower((string) setting('nfse.tributacao_federal_mode', 'per_invoice_amounts'));
        $invoiceFederalTaxes = $this->invoiceFederalTaxSnapshot(
            $invoice,
            $invoiceAmount,
            $documentItemIds,
        );
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
     * @param list<int>|null $documentItemIds
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    /**
     * @param list<int>|null $documentItemIds
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    protected function invoiceFederalTaxSnapshot(
        Invoice $invoice,
        float $invoiceAmount,
        ?array $documentItemIds = null,
    ): array {
        $taxRateById = [];

        return (new FederalTaxSnapshotBuilder())->build(
            items: $this->invoiceItemsAsArray($invoice),
            baseAmount: $invoiceAmount,
            documentItemIds: $documentItemIds,
            taxRateResolver: function (mixed $tax) use (&$taxRateById): ?float {
                return $this->resolveFederalTaxRate($tax, $taxRateById);
            },
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
                $danfse = $this->generateDanfseFromAuthorizedXml($client, $receipt);

                if (is_string($danfse) && $danfse !== '') {
                    $candidateDanfsePath = $this->buildWebDavArtifactFilePath($basePath, $invoice, $receipt, 'pdf');
                    $webDavClient->put($candidateDanfsePath, $danfse);
                    $danfsePath = $candidateDanfsePath;
                }
            } catch (\Throwable $throwable) {
                $this->safeLogError('NFS-e DANFSE artifact storage failed', [
                    'invoice_id' => $invoice->id,
                    'chave_acesso' => $receipt->chaveAcesso,
                    'exception_class' => $throwable::class,
                    'exception_message' => $throwable->getMessage(),
                    'exception_code' => $throwable->getCode(),
                    'rawXml_length' => strlen((string) ($receipt->rawXml ?? '')),
                    'rawXml_sample' => substr((string) ($receipt->rawXml ?? ''), 0, 200),
                    'trace' => $throwable->getTraceAsString(),
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

    protected function generateDanfseFromAuthorizedXml(NfseClientInterface $client, ReceiptData $receipt): string
    {
        $nfseXml = trim((string) ($receipt->rawXml ?? ''));

        if ($nfseXml === '') {
            throw new \RuntimeException('DANFSE generation requires authorized NFS-e XML payload.');
        }

        return $client->getDanfse($nfseXml);
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
