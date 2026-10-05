{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php
    $receiptStatus = (string) ($receipt->status ?? '');
    $hasReceipt = $receipt !== null;
    $statusClasses = match ($receiptStatus) {
        'emitted' => 'bg-green-100 text-green-700',
        'cancelled', 'substituted' => 'bg-gray-100 text-gray-700',
        default => 'bg-yellow-100 text-yellow-700',
    };
@endphp

<div
    id="nfse-native-fiscal-panel"
    class="rounded-lg border border-gray-200 bg-white p-4"
    data-nfse-native-panel="true"
>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h3 class="font-medium text-gray-900">{{ trans('nfse::general.native_invoice.fiscal_title') }}</h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ $hasReceipt
                    ? trans('nfse::general.native_invoice.fiscal_document_linked')
                    : trans('nfse::general.native_invoice.fiscal_pending') }}
            </p>
        </div>

        <span class="inline-flex rounded px-2 py-1 text-xs font-medium {{ $statusClasses }}">
            {{ $hasReceipt
                ? trans('nfse::general.native_invoice.status_' . ($receiptStatus !== '' ? $receiptStatus : 'unknown'))
                : trans('nfse::general.native_invoice.status_pending') }}
        </span>
    </div>

    @if($hasReceipt)
        <dl class="mt-3 space-y-1 text-sm">
            <div class="flex gap-2">
                <dt class="text-gray-500">{{ trans('nfse::general.invoices.nfse_number') }}:</dt>
                <dd class="font-medium text-gray-800">{{ $receipt->nfse_number ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ trans('nfse::general.invoices.access_key') }}:</dt>
                <dd class="break-all text-xs text-gray-700">{{ $receipt->chave_acesso ?: '—' }}</dd>
            </div>
        </dl>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        @if(!$hasReceipt)
            <button
                type="button"
                class="inline-flex items-center rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                @click="onSendEmail('{{ route('nfse.modals.invoices.emails.create', $invoice->id) }}')"
            >
                {{ trans('nfse::general.native_invoice.emit') }}
            </button>
        @else
            <a
                href="{{ route('nfse.invoices.show', $invoice->id) }}"
                class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200"
            >
                {{ trans('nfse::general.native_invoice.fiscal_details') }}
            </a>

            @if($receiptStatus === 'cancelled')
                <button
                    type="button"
                    class="inline-flex items-center rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                    @click="onSendEmail('{{ route('nfse.modals.invoices.emails.create', $invoice->id) }}')"
                >
                    {{ trans('nfse::general.invoices.reemit') }}
                </button>
            @endif
        @endif

        <a
            href="{{ route('nfse.settings.edit') }}"
            class="inline-flex items-center px-3 py-2 text-sm text-gray-600 hover:underline"
        >
            {{ trans('nfse::general.native_invoice.settings') }}
        </a>
    </div>
</div>
