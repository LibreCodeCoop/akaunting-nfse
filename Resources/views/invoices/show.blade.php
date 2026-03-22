{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.invoices.details_title') }}</x-slot>

    <x-slot name="content">
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white rounded border p-4">
                <h2 class="font-semibold mb-3">{{ trans('nfse::general.invoices.receipt_data') }}</h2>
                <dl class="grid grid-cols-1 gap-2 text-sm">
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.nfse_number') }}</dt><dd>{{ $receipt->nfse_number ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.access_key') }}</dt><dd class="break-all">{{ $receipt->chave_acesso ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.verification_code') }}</dt><dd>{{ $receipt->codigo_verificacao ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.issue_date') }}</dt><dd>{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('general.status') }}</dt><dd>{{ $receipt->status }}</dd></div>
                </dl>
            </div>

            <div class="bg-white rounded border p-4">
                <h2 class="font-semibold mb-3">{{ trans('nfse::general.invoices.invoice_data') }}</h2>
                <dl class="grid grid-cols-1 gap-2 text-sm">
                    <div><dt class="text-gray-500">{{ trans('general.invoice') }}</dt><dd>{{ $invoice->number ?? ('#' . $invoice->id) }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('general.customer') }}</dt><dd>{{ $invoice->contact?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('general.amount') }}</dt><dd>{{ $invoice->amount ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.invoices.back_to_list') }}
            </a>

            @if(($receipt->status ?? '') !== 'cancelled')
                <form action="{{ route('nfse.invoices.cancel', $invoice) }}" method="POST" onsubmit="return confirm('{{ trans('nfse::general.invoices.cancel_confirm') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm">
                        {{ trans('nfse::general.invoices.cancel') }}
                    </button>
                </form>
            @else
                <form action="{{ route('nfse.invoices.reemit', $invoice) }}" method="POST" onsubmit="return confirm('{{ trans('nfse::general.invoices.reemit_confirm') }}')">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                        {{ trans('nfse::general.invoices.reemit') }}
                    </button>
                </form>
            @endif
        </div>
    </x-slot>
</x-layouts.admin>
