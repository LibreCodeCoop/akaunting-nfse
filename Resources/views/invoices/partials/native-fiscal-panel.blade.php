{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php
    $receiptStatus = (string) ($receipt->status ?? '');
    $hasReceipt = $receipt !== null;
    $receipts = $receipts ?? collect($receipt !== null ? [$receipt] : []);
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
    role="region"
    aria-labelledby="nfse-native-fiscal-panel-title"
>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h3 id="nfse-native-fiscal-panel-title" class="font-medium text-gray-900">{{ trans('nfse::general.native_invoice.fiscal_title') }}</h3>
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
        <div class="mt-3 space-y-2" data-nfse-receipt-list="true">
            @foreach($receipts as $linkedReceipt)
                @php
                    $linkedStatus = (string) ($linkedReceipt->status ?? '');
                    $linkedStatusClasses = match ($linkedStatus) {
                        'emitted' => 'bg-green-50 border-green-200',
                        'cancelled', 'substituted' => 'bg-gray-50 border-gray-200',
                        default => 'bg-yellow-50 border-yellow-200',
                    };
                @endphp
                <article
                    class="rounded border p-3 {{ $linkedStatusClasses }}"
                    data-nfse-receipt-id="{{ $linkedReceipt->id }}"
                    data-nfse-group-key="{{ $linkedReceipt->emission_group_key ?? '' }}"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium text-gray-900">
                            {{ trans('nfse::general.invoices.nfse_number') }}:
                            {{ $linkedReceipt->nfse_number ?: '—' }}
                        </span>
                        <span class="text-xs text-gray-600">
                            {{ trans('nfse::general.native_invoice.status_' . ($linkedStatus !== '' ? $linkedStatus : 'unknown')) }}
                        </span>
                    </div>
                    <div class="mt-1 break-all text-xs text-gray-700">
                        {{ trans('nfse::general.invoices.access_key') }}:
                        {{ $linkedReceipt->chave_acesso ?: '—' }}
                    </div>
                    @if(!empty($linkedReceipt->emission_group_key))
                        <div class="mt-1 break-all text-xs text-gray-500">
                            {{ trans('nfse::general.invoices.fiscal_group_key') }}:
                            {{ $linkedReceipt->emission_group_key }}
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        @if(!$hasReceipt)
            <button
                type="button"
                class="inline-flex items-center rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                data-nfse-native-emit="true"
            >
                {{ trans('nfse::general.native_invoice.emit') }}
            </button>
        @else
            <a
                href="{{ route('nfse.invoices.artifacts.download', [$invoice->id, 'danfse']) }}"
                class="inline-flex items-center rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                data-nfse-artifact="danfse"
            >
                {{ trans('nfse::general.invoices.artifact_danfse_label') }}
            </a>

            <a
                href="{{ route('nfse.invoices.artifacts.download', [$invoice->id, 'xml']) }}"
                class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200"
                data-nfse-artifact="xml"
            >
                {{ trans('nfse::general.invoices.artifact_xml_label') }}
            </a>

            @if($receiptStatus !== 'cancelled')
                <form method="POST" action="{{ route('nfse.invoices.refresh', $invoice->id) }}" class="inline-flex">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center rounded border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        data-nfse-native-refresh="true"
                    >
                        {{ trans('nfse::general.invoices.refresh_status') }}
                    </button>
                </form>
            @endif

            @if($receiptStatus === 'cancelled')
                <button
                    type="button"
                    class="inline-flex items-center rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                    data-nfse-native-emit="true"
                >
                    {{ trans('nfse::general.invoices.reemit') }}
                </button>
            @endif

            @if($receiptStatus === 'emitted')
                <details class="w-full rounded border border-gray-200 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-800">
                        {{ trans('nfse::general.invoices.substitute') }}
                    </summary>

                    <form method="POST" action="{{ route('nfse.invoices.substitute', $invoice->id) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="nfse_substitution_receipt_id" value="{{ $receipt->id }}">

                        <div>
                            <label for="nfse-substitution-reason-{{ $invoice->id }}" class="block text-sm font-medium">
                                {{ trans('nfse::general.invoices.substitution_reason') }}
                            </label>
                            <select id="nfse-substitution-reason-{{ $invoice->id }}" name="nfse_substitution_reason" class="mt-1 w-full rounded border px-3 py-2" required>
                                @foreach(['01', '02', '03', '04', '05', '99'] as $reason)
                                    <option value="{{ $reason }}">
                                        {{ trans('nfse::general.invoices.substitution_reason_' . $reason) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="nfse-substitution-description-{{ $invoice->id }}" class="block text-sm font-medium">
                                {{ trans('nfse::general.invoices.substitution_description') }}
                            </label>
                            <textarea
                                id="nfse-substitution-description-{{ $invoice->id }}"
                                name="nfse_substitution_description"
                                minlength="15"
                                maxlength="255"
                                class="mt-1 w-full rounded border px-3 py-2"
                                placeholder="{{ trans('nfse::general.invoices.substitution_description_hint') }}"
                            ></textarea>
                        </div>

                        <p class="text-xs text-gray-600">
                            {{ trans('nfse::general.invoices.substitution_audit_hint') }}
                        </p>

                        <button type="submit" class="rounded bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700">
                            {{ trans('nfse::general.invoices.substitute_confirm') }}
                        </button>
                    </form>
                </details>

                <details class="w-full rounded border border-red-200 p-3" data-nfse-native-cancel="true">
                    <summary class="cursor-pointer text-sm font-medium text-red-700">
                        {{ trans('nfse::general.invoices.cancel') }}
                    </summary>

                    @php($cancelReasonOptions = trans('nfse::general.invoices.cancel_reason_options'))

                    <form method="POST" action="{{ route('nfse.invoices.cancel', $invoice->id) }}" class="mt-3 space-y-3">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="redirect_after_cancel" value="invoice_show">

                        <div>
                            <label for="nfse-native-cancel-reason-{{ $invoice->id }}" class="block text-sm font-medium text-gray-700">
                                {{ trans('nfse::general.invoices.cancel_modal_reason') }}
                            </label>
                            <select
                                id="nfse-native-cancel-reason-{{ $invoice->id }}"
                                name="cancel_reason"
                                class="mt-1 w-full rounded border px-3 py-2"
                                required
                            >
                                <option value="">{{ trans('nfse::general.invoices.cancel_reason_placeholder') }}</option>
                                @foreach(is_array($cancelReasonOptions) ? $cancelReasonOptions : [] as $cancelReasonOption)
                                    <option value="{{ $cancelReasonOption }}">{{ $cancelReasonOption }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="nfse-native-cancel-justification-{{ $invoice->id }}" class="block text-sm font-medium text-gray-700">
                                {{ trans('nfse::general.invoices.cancel_modal_justification') }}
                            </label>
                            <textarea
                                id="nfse-native-cancel-justification-{{ $invoice->id }}"
                                name="cancel_justification"
                                maxlength="1000"
                                class="mt-1 w-full rounded border px-3 py-2"
                                required
                            ></textarea>
                        </div>

                        <button type="submit" class="rounded bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">
                            {{ trans('nfse::general.invoices.cancel_modal_submit') }}
                        </button>
                    </form>
                </details>
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
