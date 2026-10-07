{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later

Partial rendered inside the emit-success result modal (no layout).
Variables: $invoice, $receipt, $receiptStatusLabel, $artifacts
--}}
@php
    $postEmissionStatus = is_array($postEmissionStatus ?? null) ? $postEmissionStatus : [];
    $postEmissionOverall = (string) ($postEmissionStatus['overall_status'] ?? 'idle');
    $postEmissionActive = ($postEmissionStatus['poll'] ?? false) === true;
@endphp
<div
    class="space-y-4"
    data-nfse-post-emission-status-url="{{ route('nfse.invoices.post-emission-status', $invoice->id) }}"
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
>
    <div class="flex items-center gap-2 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm {{ $postEmissionOverall === 'idle' ? 'hidden' : '' }}" data-nfse-post-emission-box aria-live="polite">
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
                        <a href="{{ $artifactData['download_url'] }}" data-nfse-artifact="{{ $artifactKey }}"
                            data-nfse-artifact-processing="{{ $artifactKey === 'danfse' ? trans('nfse::general.invoices.artifact_danfse_processing') : trans('nfse::general.invoices.artifact_xml_processing') }}" aria-disabled="false" class="inline-flex items-center gap-1 rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">
                            <svg data-nfse-artifact-spinner class="hidden h-3 w-3 animate-spin" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            <span data-nfse-artifact-label>{{ trans('nfse::general.invoices.artifact_download') }}</span>
                        </a>
                    @elseif($postEmissionActive)
                        <span
                            data-nfse-artifact="{{ $artifactKey }}"
                            data-nfse-artifact-processing="{{ $artifactKey === 'danfse' ? trans('nfse::general.invoices.artifact_danfse_processing') : trans('nfse::general.invoices.artifact_xml_processing') }}"
                            aria-disabled="true"
                            class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-500"
                        >
                            <svg data-nfse-artifact-spinner class="h-3 w-3 animate-spin" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            <span data-nfse-artifact-label>{{ $artifactKey === 'danfse' ? trans('nfse::general.invoices.artifact_danfse_processing') : trans('nfse::general.invoices.artifact_xml_processing') }}</span>
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
