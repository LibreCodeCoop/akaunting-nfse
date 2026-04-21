{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later

Partial rendered inside the emit-success result modal (no layout).
Variables: $invoice, $receipt, $receiptStatusLabel, $artifacts
--}}
<div class="space-y-4">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded border bg-gray-50 p-4">
            <h4 class="mb-3 font-semibold text-gray-800">{{ trans('nfse::general.invoices.receipt_data') }}</h4>
            <dl class="grid grid-cols-1 gap-2 text-sm">
                <div>
                    <dt class="text-gray-500">{{ trans('nfse::general.invoices.nfse_number') }}</dt>
                    <dd class="font-medium">{{ $receipt->nfse_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('nfse::general.invoices.access_key') }}</dt>
                    <dd class="break-all text-xs">{{ $receipt->chave_acesso ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('nfse::general.invoices.verification_code') }}</dt>
                    <dd>{{ $receipt->codigo_verificacao ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('nfse::general.invoices.issue_date') }}</dt>
                    <dd>{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('general.status') }}</dt>
                    <dd>{{ $receiptStatusLabel ?? ($receipt->status ?? '—') }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded border bg-gray-50 p-4">
            <h4 class="mb-3 font-semibold text-gray-800">{{ trans('nfse::general.invoices.invoice_data') }}</h4>
            <dl class="grid grid-cols-1 gap-2 text-sm">
                <div>
                    <dt class="text-gray-500">{{ trans('general.invoice') }}</dt>
                    <dd>{{ $invoice->number ?? ('#' . $invoice->id) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('nfse::general.invoices.customer') }}</dt>
                    <dd>{{ $invoice->contact?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ trans('general.amount') }}</dt>
                    <dd>{{ money($invoice->amount, default_currency(), true) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="rounded border bg-gray-50 p-4">
        <h4 class="mb-3 font-semibold text-gray-800">{{ trans('nfse::general.invoices.artifacts_title') }}</h4>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            @foreach(['danfse' => 'pdf', 'xml' => 'xml'] as $artifactKey => $artifactExtension)
                @php($artifactData = is_array($artifacts[$artifactKey] ?? null) ? $artifacts[$artifactKey] : ['path' => null, 'exists' => false, 'source' => null, 'download_url' => null])
                <div class="flex items-center justify-between rounded border border-gray-200 bg-white p-3">
                    <span class="text-sm font-medium text-gray-800">
                        {{ $artifactKey === 'danfse' ? trans('nfse::general.invoices.artifact_danfse_label') : trans('nfse::general.invoices.artifact_xml_label') }}
                    </span>
                    @if(($artifactData['exists'] ?? false) === true && is_string($artifactData['download_url'] ?? null) && ($artifactData['download_url'] ?? '') !== '')
                        <a href="{{ $artifactData['download_url'] }}" class="inline-flex items-center rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">
                            {{ trans('nfse::general.invoices.artifact_download') }}
                        </a>
                    @else
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-1 text-xs text-gray-500">{{ trans('nfse::general.invoices.artifact_missing') }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
