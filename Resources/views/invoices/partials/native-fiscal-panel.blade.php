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
    $postEmissionStatus = is_array($postEmissionStatus ?? null) ? $postEmissionStatus : [];
    $postEmissionOverall = (string) ($postEmissionStatus['overall_status'] ?? 'idle');
    $postEmissionActive = ($postEmissionStatus['poll'] ?? false) === true;
    $authorizedXmlAvailable = (bool) ($authorizedXmlAvailable ?? false);
    $xmlReady = $authorizedXmlAvailable || trim((string) ($receipt->xml_webdav_path ?? '')) !== '';
    $danfseReady = $authorizedXmlAvailable || trim((string) ($receipt->danfse_webdav_path ?? '')) !== '';
@endphp

@if(session('success'))
    <div class="mb-3 rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800" data-nfse-feedback="success">
        {{ session('success') }}
    </div>
@endif

@if(session('warning'))
    <div class="mb-3 rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800" data-nfse-feedback="warning">
        {{ session('warning') }}
    </div>
@endif

@if(session('error'))
    <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" data-nfse-feedback="error">
        {{ session('error') }}
        @if(session('nfse_gateway_error_detail'))
            <p class="mt-2"><strong>Detalhe SEFIN:</strong> {{ session('nfse_gateway_error_detail') }}</p>
        @endif
    </div>
@endif

@if(isset($errors) && ($errors->has('cancel_reason') || $errors->has('cancel_justification')))
    <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" data-nfse-feedback="validation">
        @error('cancel_reason')
            <p>{{ $message }}</p>
        @enderror
        @error('cancel_justification')
            <p>{{ $message }}</p>
        @enderror
    </div>
@endif

<div
    id="nfse-native-fiscal-panel"
    class="rounded-lg border border-gray-200 bg-white p-4"
    data-nfse-native-panel="true"
    data-nfse-post-emission-status-url="{{ $hasReceipt ? route('nfse.invoices.post-emission-status', $invoice->id) : '' }}"
    data-nfse-post-emission-state="{{ $postEmissionOverall }}"
    data-message-processing="{{ trans('nfse::general.invoices.post_processing_processing') }}"
    data-message-completed="{{ trans('nfse::general.invoices.post_processing_completed') }}"
    data-message-failed="{{ trans('nfse::general.invoices.post_processing_failed') }}"
    data-stage-pending="{{ trans('nfse::general.invoices.post_processing_stage_pending') }}"
    data-stage-processing="{{ trans('nfse::general.invoices.post_processing_stage_processing') }}"
    data-stage-retrying="{{ trans('nfse::general.invoices.post_processing_stage_retrying') }}"
    data-stage-completed="{{ trans('nfse::general.invoices.post_processing_stage_completed') }}"
    data-stage-failed="{{ trans('nfse::general.invoices.post_processing_stage_failed') }}"
    data-stage-not-requested="{{ trans('nfse::general.invoices.post_processing_stage_not_requested') }}"
    data-artifact-processing="{{ trans('nfse::general.invoices.post_processing_stage_processing') }}"
    data-artifact-missing="{{ trans('nfse::general.invoices.artifact_missing') }}"
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
        <div
            class="mt-3 flex items-center gap-2 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm {{ $postEmissionOverall === 'idle' ? 'hidden' : '' }}"
            data-nfse-post-emission-box
            aria-live="polite"
        >
            <svg data-nfse-post-emission-spinner class="h-4 w-4 animate-spin {{ $postEmissionActive ? '' : 'hidden' }}" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span data-nfse-post-emission-message>
                {{ $postEmissionOverall === 'failed'
                    ? trans('nfse::general.invoices.post_processing_failed')
                    : ($postEmissionOverall === 'completed'
                        ? trans('nfse::general.invoices.post_processing_completed')
                        : trans('nfse::general.invoices.post_processing_processing')) }}
            </span>
            <span class="ml-auto text-xs text-gray-500">
                {{ trans('nfse::general.invoices.post_processing_email') }}:
                <span data-nfse-email-status>{{ trans('nfse::general.invoices.post_processing_stage_' . ($postEmissionStatus['email_status'] ?? 'not_requested')) }}</span>
            </span>
        </div>

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
            @if($danfseReady || $postEmissionActive)
                <a
                    href="{{ route('nfse.invoices.artifacts.download', [$invoice->id, 'danfse']) }}"
                    class="inline-flex items-center gap-2 rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 {{ $danfseReady ? '' : 'pointer-events-none opacity-60' }}"
                    data-nfse-artifact="danfse"
                data-nfse-artifact-processing="{{ trans('nfse::general.invoices.artifact_danfse_processing') }}"
                    aria-disabled="{{ $danfseReady ? 'false' : 'true' }}"
                >
                    <svg data-nfse-artifact-spinner class="h-4 w-4 animate-spin {{ (!$danfseReady && $postEmissionActive) ? '' : 'hidden' }}" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span data-nfse-artifact-label>{{ $danfseReady ? trans('nfse::general.invoices.artifact_danfse_label') : trans('nfse::general.invoices.artifact_danfse_processing') }}</span>
                </a>
                @endif

            @if($xmlReady || $postEmissionActive)
                <a
                    href="{{ route('nfse.invoices.artifacts.download', [$invoice->id, 'xml']) }}"
                    class="inline-flex items-center gap-2 rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200 {{ $xmlReady ? '' : 'pointer-events-none opacity-60' }}"
                    data-nfse-artifact="xml"
                data-nfse-artifact-processing="{{ trans('nfse::general.invoices.artifact_xml_processing') }}"
                    aria-disabled="{{ $xmlReady ? 'false' : 'true' }}"
                >
                    <svg data-nfse-artifact-spinner class="h-4 w-4 animate-spin {{ (!$xmlReady && $postEmissionActive) ? '' : 'hidden' }}" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span data-nfse-artifact-label>{{ $xmlReady ? trans('nfse::general.invoices.artifact_xml_label') : trans('nfse::general.invoices.artifact_xml_processing') }}</span>
                </a>
                @endif

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
                                <option value="">{{ trans('nfse::general.invoices.cancel_modal_reason_select_placeholder') }}</option>
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
