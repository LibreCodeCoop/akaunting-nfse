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
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;

class InvoiceController extends Controller
{
    public function dashboard(): \Illuminate\View\View
    {
        $stats = $this->dashboardStats();
        $recentReceipts = $this->recentReceipts();

        return view('nfse::dashboard.index', compact('stats', 'recentReceipts'));
    }

    public function index(?Request $request = null): \Illuminate\View\View
    {
        $status = $this->normalizedIndexStatus($request?->query('status'));
        $perPage = $this->normalizedIndexPerPage($request?->query('per_page'));
        $search = $this->normalizedIndexSearch($request?->query('q'));
        $receipts = $this->receiptsForIndex($status, $perPage, $search);

        return view('nfse::invoices.index', compact('receipts', 'status', 'perPage', 'search'));
    }

    public function pending(?Request $request = null): \Illuminate\View\View
    {
        $perPage = $this->normalizedPendingPerPage($request?->query('per_page'));
        $search = $this->normalizedPendingSearch($request?->query('q'));
        $pendingInvoices = $this->pendingInvoices($perPage, $search);
        $readiness = $this->emissionReadiness();
        $checklist = $readiness['checklist'];
        $isReady = $readiness['isReady'];

        return view('nfse::invoices.pending', compact('pendingInvoices', 'checklist', 'isReady', 'perPage', 'search'));
    }

    public function show(Invoice $invoice): \Illuminate\View\View
    {
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();

        return view('nfse::invoices.show', compact('invoice', 'receipt'));
    }

    public function emit(Invoice $invoice): RedirectResponse
    {
        $this->ensureInvoiceRelationsLoaded($invoice);

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = (bool) setting('nfse.sandbox_mode', true);
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();

        $dps = new DpsData(
            cnpjPrestador:    $cnpj,
            municipioIbge:    $ibge,
            itemListaServico: setting('nfse.item_lista_servico', '0107'),
            codigoTributacaoNacional: $this->nationalTaxCode(),
            valorServico:     number_format((float) $invoice->amount, 2, '.', ''),
            aliquota:         $this->normalizedAliquota(),
            discriminacao:    $this->buildDiscriminacao($invoice),
            documentoTomador: $tomadorDocument,
            nomeTomador:      $invoice->contact?->name ?? '',
            opcaoSimplesNacional: $opcaoSimplesNacional,
            tipoAmbiente:     $sandbox ? 2 : 1,
        );

        $this->safeLogInfo('NFS-e emission payload', [
            'invoice_id' => $invoice->id,
            'opSimpNac' => $dps->opcaoSimplesNacional,
            'aliquota' => $dps->aliquota,
        ]);

        $client = $this->makeClient($sandbox);

        try {
            $receipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->gatewayErrorDetail($e);

            $this->safeLogError('NFS-e issuance rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
            ]);

            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_emit_failed'))
                ->with('nfse_gateway_error_detail', $gatewayDetail);
        } catch (NetworkException $e) {
            $this->safeLogError('NFS-e issuance failed due network/transport error', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_emit_failed'));
        } catch (PfxImportException) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_pfx_import_failed'));
        }

        $this->storeEmittedReceipt($invoice, $receipt);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_emitted', ['number' => $receipt->nfseNumber]));
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $receipt = $this->findReceiptForInvoice($invoice);

        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));

        try {
            $client->cancel($receipt->chave_acesso, trans('nfse::general.cancel_motivo_default'));
        } catch (GatewayException) {
            return redirect()->route('nfse.invoices.index')
                ->with('error', trans('nfse::general.nfse_cancel_failed'));
        }

        $receipt->update(['status' => 'cancelled']);

        return redirect()->route('nfse.invoices.index')
            ->with('success', trans('nfse::general.nfse_cancelled'));
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

        $receipt = $this->findReceiptForInvoice($invoice);

        if (($receipt->status ?? '') !== 'cancelled') {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('warning', trans('nfse::general.nfse_reemit_not_cancelled'));
        }

        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $sandboxReemit = (bool) setting('nfse.sandbox_mode', true);
        $tomadorDocument = $this->normalizedTomadorDocument($invoice->contact?->tax_number);
        $opcaoSimplesNacional = $this->normalizedOpcaoSimplesNacional();

        $dps = new DpsData(
            cnpjPrestador: setting('nfse.cnpj_prestador'),
            municipioIbge: setting('nfse.municipio_ibge'),
            itemListaServico: setting('nfse.item_lista_servico', '0107'),
            codigoTributacaoNacional: $this->nationalTaxCode(),
            valorServico: number_format((float) $invoice->amount, 2, '.', ''),
            aliquota: $this->normalizedAliquota(),
            discriminacao: $this->buildDiscriminacao($invoice),
            documentoTomador: $tomadorDocument,
            nomeTomador: $invoice->contact?->name ?? '',
            opcaoSimplesNacional: $opcaoSimplesNacional,
            tipoAmbiente: $sandboxReemit ? 2 : 1,
        );

        $client = $this->makeClient($sandboxReemit);

        try {
            $newReceipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
        } catch (GatewayException $e) {
            $gatewayDetail = $this->gatewayErrorDetail($e);

            $this->safeLogError('NFS-e reissuance rejected by SEFIN', [
                'invoice_id' => $invoice->id,
                'http_status' => $e->httpStatus,
                'upstream_payload' => $e->upstreamPayload,
                'gateway_detail' => $gatewayDetail,
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
            $firstError = $payload['erro'];
        }

        if (!is_array($firstError)) {
            return null;
        }

        $code = trim((string) ($firstError['Codigo'] ?? $firstError['codigo'] ?? ''));
        $description = trim((string) ($firstError['Descricao'] ?? $firstError['descricao'] ?? $firstError['mensagem'] ?? ''));
        $complement = trim((string) ($firstError['Complemento'] ?? $firstError['complemento'] ?? ''));

        $parts = array_filter([$code, $description, $complement], static fn (string $value): bool => $value !== '');

        if ($parts === []) {
            return null;
        }

        return implode(' - ', $parts);
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

    protected function recentReceipts(): iterable
    {
        return NfseReceipt::with('invoice')
            ->latest()
            ->take(10)
            ->get();
    }

    protected function receiptsForIndex(string $status, int $perPage, ?string $search): mixed
    {
        $query = NfseReceipt::with('invoice');

        if ($status !== 'all') {
            $query = $query->where('status', $status);
        }

        if ($search !== null) {
            $query = $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('nfse_number', 'like', '%' . $search . '%')
                    ->orWhere('chave_acesso', 'like', '%' . $search . '%')
                    ->orWhere('codigo_verificacao', 'like', '%' . $search . '%');
            });
        }

        return $query->latest()->paginate($perPage);
    }

    protected function normalizedIndexStatus(mixed $status): string
    {
        if (!is_string($status)) {
            return 'all';
        }

        $normalized = strtolower(trim($status));
        $allowed = ['all', 'emitted', 'cancelled', 'processing'];

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

    protected function pendingInvoices(int $perPage = 25, ?string $search = null): iterable
    {
        $processedInvoiceIds = NfseReceipt::query()
            ->pluck('invoice_id')
            ->filter()
            ->values()
            ->all();

        $query = Invoice::invoice()
            ->with(['contact'])
            ->when(
                $processedInvoiceIds !== [],
                static fn ($query) => $query->whereNotIn('id', $processedInvoiceIds)
            );

        if ($search !== null) {
            $query = $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('document_number', 'like', '%' . $search . '%')
                    ->orWhereHas('contact', function ($contactQuery) use ($search) {
                        $contactQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        return $query->latest()->paginate($perPage);
    }

    protected function normalizedPendingPerPage(mixed $perPage): int
    {
        $allowed = [10, 25, 50, 100];
        $normalized = is_numeric($perPage) ? (int) $perPage : 25;

        return in_array($normalized, $allowed, true) ? $normalized : 25;
    }

    protected function normalizedPendingSearch(mixed $search): ?string
    {
        if (!is_string($search)) {
            return null;
        }

        $normalized = trim($search);

        return $normalized !== '' ? $normalized : null;
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
        $transportCertificatePath = $this->projectRootPath('client.crt.pem');
        $transportPrivateKeyPath = $this->projectRootPath('client.key.pem');

        $checklist = [
            'cnpj_prestador' => $cnpj !== '',
            'municipio_ibge' => ((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => ((string) ($settings['item_lista_servico'] ?? '')) !== '',
            'certificate' => $certificatePath !== '' && is_file($certificatePath),
            'certificate_secret' => $this->hasCertificateSecret($cnpj),
            'transport_certificate' => is_file($transportCertificatePath) && is_file($transportPrivateKeyPath),
        ];

        return [
            'checklist' => $checklist,
            'isReady' => !in_array(false, $checklist, true),
        ];
    }

    protected function nationalTaxCode(): string
    {
        $configured = preg_replace('/\D+/', '', (string) setting('nfse.codigo_tributacao_nacional', '')) ?: '';

        if ($configured !== '') {
            return str_pad(substr($configured, 0, 6), 6, '0', STR_PAD_LEFT);
        }

        $lc116Code = preg_replace('/\D+/', '', (string) setting('nfse.item_lista_servico', '0107')) ?: '0107';

        return str_pad(substr($lc116Code, 0, 4), 4, '0', STR_PAD_LEFT) . '01';
    }

    protected function normalizedAliquota(): string
    {
        $configured = (string) setting('nfse.aliquota', '5.00');
        $normalized = str_replace(',', '.', trim($configured));

        return number_format((float) $normalized, 2, '.', '');
    }

    protected function normalizedOpcaoSimplesNacional(): int
    {
        $configured = (int) setting('nfse.opcao_simples_nacional', 2);

        return in_array($configured, [1, 2], true) ? $configured : 2;
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
