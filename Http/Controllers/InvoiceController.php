<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Sale\Invoice;
use Illuminate\Http\RedirectResponse;
use LibreCodeCoop\NfsePHP\Dto\DpsData;
use LibreCodeCoop\NfsePHP\Http\NfseClient;
use LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;

class InvoiceController extends Controller
{
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

        $client  = new NfseClient(secretStore: $this->makeSecretStore(), sandboxMode: $sandbox);
        $receipt = $client->emit($dps);

        NfseReceipt::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'nfse_number'         => $receipt->nfseNumber,
                'chave_acesso'        => $receipt->chaveAcesso,
                'data_emissao'        => $receipt->dataEmissao,
                'codigo_verificacao'  => $receipt->codigoVerificacao,
                'status'              => 'emitted',
            ]
        );

        return redirect()->route('nfse.invoices.show', $invoice)
            ->with('success', trans('nfse::general.nfse_emitted', ['number' => $receipt->nfseNumber]));
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->firstOrFail();

        $client = new NfseClient(
            secretStore: $this->makeSecretStore(),
            sandboxMode: (bool) setting('nfse.sandbox_mode', true),
        );

        $client->cancel($receipt->chave_acesso, trans('nfse::general.cancel_motivo_default'));

        $receipt->update(['status' => 'cancelled']);

        return redirect()->route('nfse.invoices.index')
            ->with('success', trans('nfse::general.nfse_cancelled'));
    }

    // -------------------------------------------------------------------------

    private function buildDiscriminacao(Invoice $invoice): string
    {
        return implode(' | ', $invoice->items->pluck('name')->toArray())
            ?: $invoice->description
            ?: trans('nfse::general.service_default');
    }

    private function makeSecretStore(): OpenBaoSecretStore
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
