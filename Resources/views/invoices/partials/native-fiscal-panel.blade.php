{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php
    $receiptStatus = (string) ($receipt->status ?? '');
    $hasReceipt = $receipt !== null;
    $effectiveFiscalDecision = $effectiveFiscalDecision ?? $fiscalDecision ?? null;
    $hasActiveFiscalDecision = in_array($effectiveFiscalDecision['decision'] ?? '', ['do_not_issue', 'not_applicable'], true);
    $hasCategoryDefault = ($categoryIssuancePolicy['mode'] ?? '') === 'do_not_issue';
    $hasOperatorOverride = ($fiscalDecision['decision'] ?? '') === 'issue';

    $receipts = $receipts ?? collect($receipt !== null ? [$receipt] : []);
    $statusClasses = match ($receiptStatus) {
        'emitted' => 'bg-green-100 text-green-700',
        'cancelled', 'substituted' => 'bg-gray-100 text-gray-700',
        default => 'bg-yellow-100 text-yellow-700',
    };
    if (!$hasReceipt && $hasActiveFiscalDecision) {
        $statusClasses = 'bg-gray-100 text-gray-700';
    }
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
    class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5"
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
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 id="nfse-native-fiscal-panel-title" class="text-sm font-semibold text-gray-900">{{ trans('nfse::general.native_invoice.fiscal_title') }}</h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ $hasReceipt
                    ? trans('nfse::general.native_invoice.fiscal_document_linked')
                    : ($hasActiveFiscalDecision
                        ? trans('nfse::general.invoice_decision.active_guidance')
                        : trans('nfse::general.native_invoice.fiscal_pending')) }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-xs font-medium {{ $statusClasses }}">
                {{ $hasReceipt
                    ? trans('nfse::general.native_invoice.status_' . ($receiptStatus !== '' ? $receiptStatus : 'unknown'))
                    : ($hasActiveFiscalDecision
                        ? trans('nfse::general.invoice_decision.' . $effectiveFiscalDecision['decision'])
                        : trans('nfse::general.native_invoice.status_pending')) }}
            </span>
            @can('read-nfse-settings')
                <a
                    href="{{ route('nfse.settings.edit') }}"
                    class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:border-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500"
                    data-nfse-settings-link="true"
                    title="{{ trans('nfse::general.invoices.settings_action_hint') }}"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l-1.8 1.8a1.7 1.7 0 0 0-1.9-.3l-.8.3a1.7 1.7 0 0 0-1 1.6v.7h-2.6v-.7a1.7 1.7 0 0 0-1-1.6l-.8-.3a1.7 1.7 0 0 0-1.9.3l-1.8-1.8a1.7 1.7 0 0 0 .3-1.9l-.3-.8a1.7 1.7 0 0 0-1.6-1H4v-2.6h.7a1.7 1.7 0 0 0 1.6-1l.3-.8a1.7 1.7 0 0 0-.3-1.9l1.8-1.8a1.7 1.7 0 0 0 1.9.3l.8-.3a1.7 1.7 0 0 0 1-1.6V3h2.6v.7a1.7 1.7 0 0 0 1 1.6l.8.3a1.7 1.7 0 0 0 1.9-.3l1.8 1.8a1.7 1.7 0 0 0-.3 1.9l.3.8a1.7 1.7 0 0 0 1.6 1h.7v2.6h-.7a1.7 1.7 0 0 0-1.6 1z" />
                    </svg>
                    <span>{{ trans('nfse::general.invoices.settings_action') }}</span>
                </a>
            @endcan
        </div>
    </div>

    @if(!$hasReceipt)
        <section class="mt-4 space-y-3" data-nfse-invoice-workflow="true"
            aria-labelledby="nfse-invoice-workflow-title">
            <h4 id="nfse-invoice-workflow-title" class="text-sm font-semibold text-gray-900">
                {{ trans('nfse::general.invoice_decision.next_step') }}
            </h4>
            @if(!empty($categoryIssuancePolicy))
                <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700"
                    data-nfse-category-guidance="{{ $categoryIssuancePolicy['mode'] }}">
                    {{ trans('nfse::general.invoice_decision.category_default') }}:
                    <strong>{{ trans('nfse::general.category_issuance.' . $categoryIssuancePolicy['mode']) }}</strong>.
                    {{ trans('nfse::general.invoice_decision.category_not_exemption') }}
                </p>
            @endif

            @if($hasActiveFiscalDecision)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3"
                    data-nfse-decision-current="{{ $effectiveFiscalDecision['decision'] }}"
                    data-nfse-decision-source="{{ $effectiveFiscalDecision['source'] ?? 'operator' }}">
                    <h5 class="text-sm font-semibold text-amber-900">
                        {{ trans('nfse::general.invoice_decision.' . $effectiveFiscalDecision['decision']) }}
                    </h5>
                    <p class="text-sm text-amber-900">{{ $effectiveFiscalDecision['justification'] }}</p>
                    @if(($effectiveFiscalDecision['source'] ?? 'operator') === 'category')
                        <p class="text-xs text-amber-800">
                            {{ trans('nfse::general.invoice_decision.category_applied') }}
                        </p>
                        @can('update-sales-invoices')
                            <details class="rounded border border-amber-200 bg-white p-3" data-nfse-category-exception="true">
                                <summary class="cursor-pointer text-sm font-medium text-indigo-700">
                                    {{ trans('nfse::general.invoice_decision.issue_override') }}
                                </summary>
                                <form method="POST" action="{{ route('nfse.invoices.fiscal-decision.store', $invoice->id) }}"
                                    class="mt-3 space-y-3">
                                    @csrf
                                    <input type="hidden" name="decision" value="issue">
                                    <input type="hidden" name="reason" value="operational_review">
                                    <label class="block text-xs font-medium">
                                        {{ trans('nfse::general.invoice_decision.justification') }}
                                        <textarea name="justification" required minlength="12" maxlength="4000"
                                            rows="2" class="mt-1 w-full rounded border px-2 py-2 text-sm"></textarea>
                                    </label>
                                    <button type="submit" class="rounded bg-indigo-700 px-3 py-2 text-sm text-white">
                                        {{ trans('nfse::general.invoice_decision.issue_override_save') }}
                                    </button>
                                </form>
                            </details>
                        @endcan
                    @else
                        @can('update-sales-invoices')
                            <form method="POST" action="{{ route('nfse.invoices.fiscal-decision.store', $invoice->id) }}"
                                class="space-y-2" data-nfse-decision-reversal-form="true">
                                @csrf
                                <input type="hidden" name="decision" value="reversed">
                                <input type="hidden" name="reason" value="reconsidered">
                                <label class="block text-xs font-medium">
                                    {{ trans('nfse::general.invoice_decision.justification') }}
                                    <textarea name="justification" required minlength="12" maxlength="4000"
                                        rows="2" class="mt-1 w-full rounded border px-2 py-2 text-sm"></textarea>
                                </label>
                                <button type="submit" class="rounded border border-amber-300 bg-white px-3 py-2 text-sm font-medium">
                                    {{ trans('nfse::general.invoice_decision.reversed') }}
                                </button>
                            </form>
                        @endcan
                    @endif
                </div>
            @else
                @if($hasOperatorOverride)
                    <p class="rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs"
                        data-nfse-decision-current="issue">
                        {{ trans('nfse::general.invoice_decision.issue_override_active') }}
                    </p>
                @endif
                @can('update-sales-invoices')
                    <div class="space-y-2" data-nfse-exclusive-fiscal-choices="true">
                        <details name="nfse-operation-{{ $invoice->id }}" class="rounded-lg border border-gray-200 bg-white"
                            data-nfse-choice="issue" open>
                            <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold hover:bg-gray-50">
                                {{ trans('nfse::general.invoice_decision.choice_issue') }}
                            </summary>
                            <div class="border-t px-4 py-3">
                                <p class="mb-3 text-xs text-gray-600">
                                    {{ trans('nfse::general.invoice_decision.choice_issue_help') }}
                                </p>
                                <button type="button" class="rounded bg-green-700 px-4 py-2 text-sm font-medium text-white"
                                    data-nfse-native-emit="true">
                                    {{ trans('nfse::general.native_invoice.emit') }}
                                </button>
                            </div>
                        </details>
                        <details name="nfse-operation-{{ $invoice->id }}" class="rounded-lg border border-gray-200 bg-white"
                            data-nfse-choice="do_not_issue">
                            <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold hover:bg-gray-50">
                                {{ trans('nfse::general.invoice_decision.choice_not_issue') }}
                            </summary>
                            <div class="border-t px-4 py-3">
                                <p class="mb-3 text-xs text-gray-600">
                                    {{ trans('nfse::general.invoice_decision.help') }}
                                </p>
                                <form method="POST" action="{{ route('nfse.invoices.fiscal-decision.store', $invoice->id) }}"
                                    class="grid gap-3 sm:grid-cols-2" data-nfse-decision-form="true">
                                    @csrf
                                    <label class="text-xs font-medium">
                                        {{ trans('nfse::general.invoice_decision.action') }}
                                        <select name="decision" required class="mt-1 w-full rounded border px-2 py-2 text-sm">
                                            <option value="do_not_issue">{{ trans('nfse::general.invoice_decision.do_not_issue') }}</option>
                                            <option value="not_applicable">{{ trans('nfse::general.invoice_decision.not_applicable') }}</option>
                                        </select>
                                    </label>
                                    <label class="text-xs font-medium">
                                        {{ trans('nfse::general.invoice_decision.reason') }}
                                        <select name="reason" required class="mt-1 w-full rounded border px-2 py-2 text-sm">
                                            @php
                                                $reasonOptions = \Modules\Nfse\Application\InvoiceFiscalDecision::REASONS;
                                            @endphp
                                            @foreach($reasonOptions as $reason)
                                                @if($reason !== 'reconsidered')
                                                    <option value="{{ $reason }}">{{ trans('nfse::general.invoice_decision.reason_' . $reason) }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="text-xs font-medium sm:col-span-2">
                                        {{ trans('nfse::general.invoice_decision.justification') }}
                                        <textarea name="justification" required minlength="12" maxlength="4000"
                                            rows="2" class="mt-1 w-full rounded border px-2 py-2 text-sm"></textarea>
                                    </label>
                                    <label class="text-xs font-medium sm:col-span-2">
                                        {{ trans('nfse::general.invoice_decision.evidence') }}
                                        <input name="evidence_reference" maxlength="255" type="text"
                                            class="mt-1 w-full rounded border px-2 py-2 text-sm">
                                    </label>
                                    <button type="submit" class="rounded bg-indigo-700 px-3 py-2 text-sm text-white">
                                        {{ trans('nfse::general.invoice_decision.save') }}
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>
                @endcan
            @endif

            @if($errors->has('decision'))
                <p role="alert" class="text-sm text-red-700">{{ $errors->first('decision') }}</p>
            @endif
        </section>
    @endif

    @if(!empty($fiscalDecisionHistory))
        <details class="mt-4 rounded border border-gray-200 p-3" data-nfse-decision-history="true">
            <summary class="cursor-pointer text-sm font-medium">
                {{ trans('nfse::general.invoice_decision.history') }} ({{ count($fiscalDecisionHistory) }})
            </summary>
            <ol class="mt-2 space-y-2 text-xs">
                @foreach($fiscalDecisionHistory as $entry)
                    <li>
                        {{ $entry['recorded_at'] }} · #{{ $entry['actor_id'] }} ·
                        {{ trans('nfse::general.invoice_decision.' . $entry['decision']) }} ·
                        {{ $entry['reason'] }} · {{ $entry['justification'] }}
                        @if($entry['evidence_reference']) — {{ $entry['evidence_reference'] }} @endif
                    </li>
                @endforeach
            </ol>
        </details>
    @endif

    @if(!empty($fiscalEventGroups) && !$hasActiveFiscalDecision)
        <details class="mt-4 rounded-lg border border-gray-200" data-nfse-technical-issuance="true">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ trans('nfse::general.invoice_decision.technical_details') }}
            </summary>
            <section class="border-t bg-gray-50 p-3" data-nfse-fiscal-event-evidence="true">
            <h4 class="text-sm font-semibold text-gray-800">{{ trans('nfse::general.fiscal_event.title') }}</h4>
            <p class="mt-1 text-xs text-gray-600">{{ trans('nfse::general.fiscal_event.help') }}</p>

            <ul class="mt-2 space-y-1 text-xs text-gray-700">
                @foreach($fiscalEventGroups as $group)
                    <li data-nfse-fiscal-event-group="{{ $group['group_key'] }}">
                        {{ $group['service_code'] }} —
                        @if(!empty($group['taxable_event_date']))
                            <span class="font-medium">{{ $group['taxable_event_date'] }}</span>
                            ({{ trans('nfse::general.fiscal_event.attested') }})
                        @else
                            <span class="font-medium text-amber-700">{{ trans('nfse::general.fiscal_event.unverified') }}</span>
                        @endif
                        <span class="text-gray-500">
                            {{ trans('nfse::general.fiscal_event.calendar') }}:
                            {{ trans('nfse::general.fiscal_event.status_' . ($group['diagnostics'][0]['status'] ?? 'unverifiable')) }}
                        </span>
                    </li>
                @endforeach
            </ul>

            @can('update-sales-invoices')
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-medium text-indigo-700">{{ trans('nfse::general.fiscal_event.record') }}</summary>
                    <form method="POST" action="{{ route('nfse.invoices.fiscal-event-evidence.store', $invoice->id) }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-nfse-fiscal-event-form="true">
                        @csrf
                        <div class="sm:col-span-2">
                            <label for="nfse-event-group-{{ $invoice->id }}" class="block text-xs font-medium">{{ trans('nfse::general.fiscal_event.group') }}</label>
                            <select id="nfse-event-group-{{ $invoice->id }}" name="fiscal_group_key" class="mt-1 w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm" required>
                                <option value="">{{ trans('nfse::general.fiscal_event.select_group') }}</option>
                                @foreach($fiscalEventGroups as $group)
                                    <option value="{{ $group['group_key'] }}">{{ $group['service_code'] }} — {{ $group['amount'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="nfse-event-date-{{ $invoice->id }}" class="block text-xs font-medium">{{ trans('nfse::general.fiscal_event.date') }}</label>
                            <input id="nfse-event-date-{{ $invoice->id }}" type="date" name="taxable_event_date" class="mt-1 w-full rounded border border-gray-300 px-2 py-2 text-sm" required>
                        </div>
                        <div>
                            <label for="nfse-event-reference-{{ $invoice->id }}" class="block text-xs font-medium">{{ trans('nfse::general.fiscal_event.reference') }}</label>
                            <input id="nfse-event-reference-{{ $invoice->id }}" type="text" name="source_reference" minlength="5" maxlength="255" class="mt-1 w-full rounded border border-gray-300 px-2 py-2 text-sm" required>
                        </div>
                        <label class="flex items-start gap-2 text-xs text-gray-700 sm:col-span-2">
                            <input type="checkbox" name="acknowledge" value="1" class="mt-0.5" required>
                            {{ trans('nfse::general.fiscal_event.acknowledge') }}
                        </label>
                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded bg-indigo-700 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-800">{{ trans('nfse::general.fiscal_event.save') }}</button>
                        </div>
                    </form>
                </details>
            @endcan
            </section>
        </details>
    @endif

    @if($hasReceipt)
        <div
            class="mt-4 flex flex-wrap items-center gap-2 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm {{ $postEmissionOverall === 'idle' ? 'hidden' : '' }}"
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
            <span class="sm:ml-auto text-xs text-gray-500">
                {{ trans('nfse::general.invoices.post_processing_email') }}:
                <span data-nfse-email-status>{{ trans('nfse::general.invoices.post_processing_stage_' . ($postEmissionStatus['email_status'] ?? 'not_requested')) }}</span>
            </span>
        </div>

        <div class="mt-4 flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
            <h4 class="text-sm font-semibold text-gray-800">{{ trans('nfse::general.invoices.receipt_list_title') }}</h4>
            @if($receipts->count() > 1)
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ trans('nfse::general.invoices.receipt_list_count', ['count' => $receipts->count()]) }}</span>
            @endif
        </div>
        <div class="mt-3 space-y-3" data-nfse-receipt-list="true">
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
                    class="rounded-lg border p-3 sm:p-4 {{ $linkedStatusClasses }}"
                    data-nfse-receipt-id="{{ $linkedReceipt->id }}"
                    data-nfse-group-key="{{ $linkedReceipt->emission_group_key ?? '' }}"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium text-gray-900">
                            {{ trans('nfse::general.invoices.nfse_number') }}:
                            {{ $linkedReceipt->nfse_number ?: '—' }}
                        </span>
                        <span class="rounded-full px-2 py-1 text-xs font-medium {{ $linkedStatus === 'emitted' ? 'bg-green-100 text-green-800' : ($linkedStatus === 'cancelled' || $linkedStatus === 'substituted' ? 'bg-gray-200 text-gray-700' : 'bg-yellow-100 text-yellow-800') }}">
                            {{ trans('nfse::general.native_invoice.status_' . ($linkedStatus !== '' ? $linkedStatus : 'unknown')) }}
                        </span>
                    </div>
                    <div class="mt-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-medium text-gray-600">{{ trans('nfse::general.invoices.access_key') }}:</span>
                            @if(trim((string) ($linkedReceipt->chave_acesso ?? '')) !== '')
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs" data-nfse-copy-feedback role="status" aria-live="polite"></span>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 disabled:opacity-60"
                                        data-nfse-copy-access-key="true"
                                        data-nfse-copy-success="{{ trans('nfse::general.invoices.copy_access_key_success') }}"
                                        data-nfse-copy-error="{{ trans('nfse::general.invoices.copy_access_key_error') }}"
                                        aria-label="{{ trans('nfse::general.invoices.copy_access_key_for_receipt', ['number' => $linkedReceipt->nfse_number ?: '—']) }}"
                                        title="{{ trans('nfse::general.invoices.copy_access_key_for_receipt', ['number' => $linkedReceipt->nfse_number ?: '—']) }}"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <rect x="8" y="8" width="12" height="12" rx="2" />
                                            <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
                                        </svg>
                                        {{ trans('nfse::general.invoices.copy_access_key_short') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                        <span class="mt-1 block break-all font-mono text-xs leading-relaxed text-gray-700" data-nfse-access-key>{{ $linkedReceipt->chave_acesso ?: '—' }}</span>
                    </div>
                    @if(in_array($linkedStatus, ['cancelled', 'substituted'], true))
                        <details class="group mt-3 overflow-hidden rounded-md border border-gray-300 bg-white text-sm" data-nfse-history-artifacts="{{ $linkedReceipt->id }}" data-nfse-label-show="{{ trans('nfse::general.invoices.artifacts_show_documents') }}" data-nfse-label-hide="{{ trans('nfse::general.invoices.artifacts_hide_documents') }}">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">
                                <span data-nfse-disclosure-label>{{ trans('nfse::general.invoices.artifacts_show_documents') }}</span>
                                <svg data-nfse-disclosure-chevron class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </summary>
                            <div class="flex flex-wrap gap-2 border-t border-gray-200 px-3 py-3">
                                <a class="inline-flex items-center rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" href="{{ route('nfse.ledger.artifacts.download', ['receipt' => $linkedReceipt->id, 'artifact' => 'danfse']) }}">{{ trans('nfse::general.invoices.artifact_danfse_label') }}</a>
                                <a class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200" href="{{ route('nfse.ledger.artifacts.download', ['receipt' => $linkedReceipt->id, 'artifact' => 'xml']) }}">{{ trans('nfse::general.invoices.artifact_xml_label') }}</a>
                            </div>
                        </details>
                    @endif
                    @if($receipt !== null && (int) $linkedReceipt->id === (int) $receipt->id && $linkedStatus === 'emitted')
                        <div class="mt-3 flex flex-wrap gap-2 border-t border-green-200 pt-3" data-nfse-current-artifacts="{{ $linkedReceipt->id }}">
                            @if($danfseReady || $postEmissionActive)
                                <a
                                    @if($danfseReady)
                                        href="{{ route('nfse.ledger.artifacts.download', ['receipt' => $linkedReceipt->id, 'artifact' => 'danfse']) }}"
                                    @endif
                                    class="inline-flex items-center gap-2 rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 {{ $danfseReady ? '' : 'pointer-events-none opacity-60' }}"
                                    data-nfse-artifact="danfse"
                                    data-nfse-artifact-ready-label="{{ trans('nfse::general.invoices.artifact_danfse_label') }}"
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
                                    @if($xmlReady)
                                        href="{{ route('nfse.ledger.artifacts.download', ['receipt' => $linkedReceipt->id, 'artifact' => 'xml']) }}"
                                    @endif
                                    class="inline-flex items-center gap-2 rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200 {{ $xmlReady ? '' : 'pointer-events-none opacity-60' }}"
                                    data-nfse-artifact="xml"
                                    data-nfse-artifact-ready-label="{{ trans('nfse::general.invoices.artifact_xml_label') }}"
                                    data-nfse-artifact-processing="{{ trans('nfse::general.invoices.artifact_xml_processing') }}"
                                    aria-disabled="{{ $xmlReady ? 'false' : 'true' }}"
                                >
                                    <svg data-nfse-artifact-spinner class="h-4 w-4 animate-spin {{ (!$xmlReady && $postEmissionActive) ? '' : 'hidden' }}" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span data-nfse-artifact-label>{{ $xmlReady ? trans('nfse::general.invoices.artifact_xml_label') : trans('nfse::general.invoices.artifact_xml_processing') }}</span>
                                </a>
                            @endif
                        </div>
                    @endif
                    @if(!empty($linkedReceipt->emission_group_key))
                        <div class="mt-1 break-all text-xs text-gray-500">
                            {{ trans('nfse::general.invoices.fiscal_group_key') }}:
                            {{ $linkedReceipt->emission_group_key }}
                        </div>
                    @endif
                    @if($receipt !== null && (int) $receipt->id === (int) $linkedReceipt->id)
                        @can('update-sales-invoices')
                            @if($linkedStatus !== 'cancelled')
                                <section class="mt-4 border-t border-gray-200 pt-3" data-nfse-receipt-actions="{{ $linkedReceipt->id }}" data-nfse-actions="true">
                                    <h5 class="text-xs font-semibold text-gray-800">{{ trans('nfse::general.invoices.receipt_action_title', ['number' => $linkedReceipt->nfse_number ?: '—']) }}</h5>
                                    <form method="POST" action="{{ route('nfse.invoices.refresh', $invoice->id) }}" class="mt-2">
                                        @csrf
                                        <input type="hidden" name="nfse_receipt_id" value="{{ $linkedReceipt->id }}">
                                        <input type="hidden" name="redirect_after_refresh" value="invoice_show">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500"
                                            data-nfse-native-refresh="true"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M20 7v5h-5M4 17v-5h5" stroke-linecap="round" stroke-linejoin="round" />
                                                <path d="M5.5 9a7 7 0 0 1 12-2L20 12M4 12l2.5 5a7 7 0 0 0 12-2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            {{ trans('nfse::general.invoices.refresh_from_sefin') }}
                                        </button>
                                    </form>
                                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                                        {{ trans('nfse::general.invoices.refresh_from_sefin_hint', ['number' => $linkedReceipt->nfse_number ?: '—']) }}
                                    </p>
                                    @if($linkedStatus === 'emitted')
                                        <div class="mt-3 space-y-2">
                                            <details class="group w-full overflow-hidden rounded-md border border-gray-200" data-nfse-native-substitute="true">
                                                <summary class="flex list-none cursor-pointer items-center justify-between gap-2 px-3 py-3 text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">
                                                    <span>{{ trans('nfse::general.invoices.substitute_receipt', ['number' => $linkedReceipt->nfse_number ?: '—']) }}</span>
                                                    <svg data-nfse-disclosure-chevron class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </summary>

                                                <form method="POST" action="{{ route('nfse.invoices.substitute', $invoice->id) }}" class="space-y-3 border-t border-gray-200 p-3" data-nfse-substitution-form="true">
                                                    @csrf
                                                    <input type="hidden" name="nfse_substitution_receipt_id" value="{{ $linkedReceipt->id }}">

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

                                            <details class="group w-full overflow-hidden rounded-md border border-red-200" data-nfse-native-cancel="true">
                                                <summary class="flex list-none cursor-pointer items-center justify-between gap-2 px-3 py-3 text-sm font-medium text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-500">
                                                    <span>{{ trans('nfse::general.invoices.cancel_receipt', ['number' => $linkedReceipt->nfse_number ?: '—']) }}</span>
                                                    <svg data-nfse-disclosure-chevron class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </summary>

                                                @php($cancelReasonOptions = trans('nfse::general.invoices.cancel_reason_options'))

                                                <form method="POST" action="{{ route('nfse.invoices.cancel', $invoice->id) }}" class="space-y-3 border-t border-red-200 p-3">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="redirect_after_cancel" value="invoice_show">
                                                    <input type="hidden" name="nfse_receipt_id" value="{{ $linkedReceipt->id }}">

                                                    <p class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                                        {{ trans('nfse::general.invoices.cancel_receipt_warning', ['number' => $linkedReceipt->nfse_number ?: '—']) }}
                                                    </p>

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
                                                            @foreach(is_array($cancelReasonOptions) ? $cancelReasonOptions : [] as $cancelReasonCode => $cancelReasonOption)
                                                                <option value="{{ $cancelReasonCode }}">{{ $cancelReasonOption }}</option>
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
                                                            minlength="15"
                                                            maxlength="255"
                                                            aria-describedby="nfse-native-cancel-justification-help-{{ $invoice->id }}"
                                                            class="mt-1 w-full rounded border px-3 py-2"
                                                            required
                                                        ></textarea>
                                                        <p id="nfse-native-cancel-justification-help-{{ $invoice->id }}" class="mt-1 text-xs text-gray-500">
                                                            {{ trans('nfse::general.invoices.cancel_justification_length') }}
                                                        </p>
                                                    </div>

                                                    <button type="submit" class="rounded bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">
                                                        {{ trans('nfse::general.invoices.cancel_modal_submit') }}
                                                    </button>
                                                </form>
                                            </details>

                                        </div>
                                    @endif
                                </section>
                            @elseif($linkedStatus === 'cancelled' && !$hasActiveFiscalDecision)
                                <div class="mt-3 border-t border-gray-200 pt-3" data-nfse-receipt-actions="{{ $linkedReceipt->id }}">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-700"
                                        data-nfse-native-emit="true"
                                    >
                                        {{ trans('nfse::general.invoices.reemit_receipt', ['number' => $linkedReceipt->nfse_number ?: '—']) }}
                                    </button>
                                </div>
                            @endif
                        @endcan
                    @endif
                </article>
            @endforeach
        </div>
    @endif

</div>
