<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Sale\Invoice;
use Illuminate\Http\RedirectResponse;
use LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use LibreCodeCoop\NfsePHP\Dto\DpsData;
use LibreCodeCoop\NfsePHP\Dto\ReceiptData;
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

    public function index(): \Illuminate\View\View
    {
        $receipts = NfseReceipt::with('invoice')
            ->latest()
            ->paginate(25);

        return view('nfse::invoices.index', compact('receipts'));
    }

    public function show(Invoice $invoice): \Illuminate\View\View
    {
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();

        return view('nfse::invoices.show', compact('invoice', 'receipt'));
    }

    public function emit(Invoice $invoice): RedirectResponse
    {
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
        $receipt = $client->emit($dps);

        $this->storeEmittedReceipt($invoice, $receipt);

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_emitted', ['number' => $receipt->nfseNumber]));
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $receipt = $this->findReceiptForInvoice($invoice);

        $client = $this->makeClient((bool) setting('nfse.sandbox_mode', true));

        $client->cancel($receipt->chave_acesso, trans('nfse::general.cancel_motivo_default'));

        $receipt->update(['status' => 'cancelled']);

        return redirect()->route('nfse.invoices.index')
            ->with('success', trans('nfse::general.nfse_cancelled'));
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

    protected function makeClient(bool $sandboxMode): NfseClientInterface
    {
        return new NfseClient(secretStore: $this->makeSecretStore(), sandboxMode: $sandboxMode);
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
