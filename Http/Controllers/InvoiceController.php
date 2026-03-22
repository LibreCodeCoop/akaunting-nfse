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
        $readiness = $this->emissionReadiness();

        if (($readiness['isReady'] ?? false) !== true) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.invoices.emit_blocked_not_ready'));
        }

        $cnpj    = setting('nfse.cnpj_prestador');
        $ibge    = setting('nfse.municipio_ibge');
        $sandbox = (bool) setting('nfse.sandbox_mode', true);

        $dps = new DpsData(
            cnpjPrestador:    $cnpj,
            municipioIbge:    $ibge,
            itemListaServico: setting('nfse.item_lista_servico', '0107'),
            valorServico:     number_format((float) $invoice->amount, 2, '.', ''),
            aliquota:         setting('nfse.aliquota', '5.00'),
            discriminacao:    $this->buildDiscriminacao($invoice),
            documentoTomador: $invoice->contact?->tax_number ?? '',
            nomeTomador:      $invoice->contact?->name ?? '',
        );

        $client = $this->makeClient($sandbox);

        try {
            $receipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
        } catch (GatewayException) {
            return redirect()->route('nfse.invoices.pending')
                ->with('error', trans('nfse::general.nfse_emit_failed'));
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

        $dps = new DpsData(
            cnpjPrestador: setting('nfse.cnpj_prestador'),
            municipioIbge: setting('nfse.municipio_ibge'),
            itemListaServico: setting('nfse.item_lista_servico', '0107'),
            valorServico: number_format((float) $invoice->amount, 2, '.', ''),
            aliquota: setting('nfse.aliquota', '5.00'),
            discriminacao: $this->buildDiscriminacao($invoice),
            documentoTomador: $invoice->contact?->tax_number ?? '',
            nomeTomador: $invoice->contact?->name ?? '',
        );

        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));

        try {
            $newReceipt = $client->emit($dps);
        } catch (SecretStoreException) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_secret_store_failed'));
        } catch (GatewayException) {
            return redirect()->route('nfse.invoices.show', $invoice)
                ->with('error', trans('nfse::general.nfse_reemit_failed'));
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

        $checklist = [
            'cnpj_prestador' => $cnpj !== '',
            'municipio_ibge' => ((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => ((string) ($settings['item_lista_servico'] ?? '')) !== '',
            'certificate' => $certificatePath !== '' && is_file($certificatePath),
            'certificate_secret' => $this->hasCertificateSecret($cnpj),
        ];

        return [
            'checklist' => $checklist,
            'isReady' => !in_array(false, $checklist, true),
        ];
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
            ),
            secretStore: $this->makeSecretStore(),
        );
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
