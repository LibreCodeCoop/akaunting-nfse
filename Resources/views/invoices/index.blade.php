{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.invoices.title') }}</x-slot>

    <x-slot name="content">
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if(session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                {{ session('warning') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}

                @if(session('nfse_gateway_error_detail'))
                    <p class="mt-2 text-sm">
                        <strong>Detalhe SEFIN:</strong> {{ session('nfse_gateway_error_detail') }}
                    </p>
                @endif
            </div>
        @endif

        @if($errors->has('cancel_reason') || $errors->has('cancel_justification'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                @error('cancel_reason')
                    <p>{{ $message }}</p>
                @enderror
                @error('cancel_justification')
                    <p>{{ $message }}</p>
                @enderror
            </div>
        @endif

        @php
            $listingRouteName = request()->routeIs('nfse.dashboard.index') ? 'nfse.dashboard.index' : 'nfse.invoices.index';
            $currentStatus = $status ?? 'all';
            $isPendingStatus = $currentStatus === 'pending';
            $visibleTotal = (int) ($overviewCounts['total'] ?? 0);
            $visibleEmitted = (int) ($overviewCounts['emitted'] ?? 0);
            $visibleProcessing = (int) ($overviewCounts['processing'] ?? 0);
            $visibleCancelled = (int) ($overviewCounts['cancelled'] ?? 0);
            $visiblePending = (int) ($overviewCounts['pending'] ?? 0);
            $isReady = $pendingReadiness['isReady'] ?? true;
            $checklist = $pendingReadiness['checklist'] ?? [];
            $searchStringFilters = [
                [
                    'key' => 'status',
                    'value' => trans_choice('general.statuses', 1),
                    'type' => 'select',
                    'multiple' => true,
                    'values' => [
                        'all' => trans('nfse::general.invoices.filter_all'),
                        'pending' => trans('nfse::general.invoices.filter_pending'),
                        'emitted' => trans('nfse::general.invoices.filter_emitted'),
                        'processing' => trans('nfse::general.invoices.filter_processing'),
                        'cancelled' => trans('nfse::general.invoices.filter_cancelled'),
                    ],
                ],
                [
                    'key' => 'data_emissao',
                    'value' => trans('nfse::general.invoices.issue_date'),
                    'type' => 'date',
                    'values' => [],
                ],
            ];
        @endphp

        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">{{ trans('nfse::general.invoices.listing_overview') }}</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
                <div class="rounded border border-gray-200 bg-gray-50 px-4 py-3">
                    <p class="text-xs text-gray-500 uppercase">{{ trans('nfse::general.invoices.filter_all') }}</p>
                    <p class="text-2xl font-semibold text-gray-800">{{ $visibleTotal }}</p>
                </div>
                <div class="rounded border border-green-200 bg-green-50 px-4 py-3">
                    <p class="text-xs text-green-700 uppercase">{{ trans('nfse::general.invoices.filter_emitted') }}</p>
                    <p class="text-2xl font-semibold text-green-700">{{ $visibleEmitted }}</p>
                </div>
                <div class="rounded border border-blue-200 bg-blue-50 px-4 py-3">
                    <p class="text-xs text-blue-700 uppercase">{{ trans('nfse::general.invoices.filter_processing') }}</p>
                    <p class="text-2xl font-semibold text-blue-700">{{ $visibleProcessing }}</p>
                </div>
                <div class="rounded border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-xs text-red-700 uppercase">{{ trans('nfse::general.invoices.filter_cancelled') }}</p>
                    <p class="text-2xl font-semibold text-red-700">{{ $visibleCancelled }}</p>
                </div>
                <div class="rounded border border-indigo-200 bg-indigo-50 px-4 py-3">
                    <p class="text-xs text-indigo-700 uppercase">{{ trans('nfse::general.invoices.filter_pending') }}</p>
                    <p class="text-2xl font-semibold text-indigo-700">{{ $visiblePending }}</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-4">
            <form action="{{ route('nfse.invoices.refresh-all') }}" method="POST">
                @csrf
                <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-sm">
                    {{ trans('nfse::general.invoices.refresh_all_statuses') }}
                </button>
            </form>
            <a href="{{ route('nfse.settings.edit') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_settings') }}
            </a>
        </div>

        <x-form method="GET" action="{{ route($listingRouteName) }}">
            <x-search-string :filters="$searchStringFilters" />
        </x-form>

        @if($isPendingStatus && !$isReady)
            <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 px-4 py-3 rounded mb-4">
                <p>{{ trans('nfse::general.invoices.readiness_incomplete') }}</p>
                @php $missingItems = array_keys(array_filter($checklist, fn($value) => !$value)); @endphp
                @if(count($missingItems) > 0)
                    <ul class="mt-2 list-disc list-inside text-sm">
                        @foreach($missingItems as $key)
                            <li>{{ trans('nfse::general.readiness.checks.' . $key) }}</li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('nfse.settings.edit', ['tab' => 'services']) }}" class="underline mt-2 inline-block text-sm">
                    {{ trans('nfse::general.go_to_settings') }}
                </a>
            </div>
        @endif

        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('general.invoice') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('nfse::general.invoices.customer') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('general.amount') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('general.date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('general.status') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trans('general.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @if($isPendingStatus)
                        @forelse($pendingInvoices as $invoice)
                            <tr class="group hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('nfse.invoices.show', $invoice) }}" class="text-indigo-700 hover:underline">
                                        {{ $invoice->number ?? $invoice->document_number ?? ('#' . $invoice->id) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $invoice->contact?->name ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ money($invoice->amount, default_currency(), true) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ optional($invoice->issued_at)->format('d/m/Y H:i') ?? optional($invoice->created_at)->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700">
                                        {{ trans('nfse::general.invoices.filter_pending') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form action="{{ route('nfse.invoices.emit', $invoice) }}" method="POST" class="inline-flex">
                                        @csrf
                                        <button
                                            type="submit"
                                            @if(!$isReady) disabled @endif
                                            title="{{ trans('nfse::general.invoices.emit_now') }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded border @if($isReady) border-indigo-200 text-indigo-700 hover:bg-indigo-50 @else border-gray-300 text-gray-400 cursor-not-allowed @endif"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path d="M4 10h8.17l-2.58-2.59L11 6l5 5-5 5-1.41-1.41L12.17 12H4v-2z" />
                                            </svg>
                                            <span class="sr-only">{{ trans('nfse::general.invoices.emit_now') }}</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">{{ trans('nfse::general.invoices.no_pending') }}</td>
                            </tr>
                        @endforelse
                    @else
                        @forelse($receipts as $receipt)
                            @php
                                $statusClass = match ($receipt->status ?? '') {
                                    'emitted' => 'bg-green-100 text-green-700',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                    'processing' => 'bg-blue-100 text-blue-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };

                                $statusLabel = match ($receipt->status ?? '') {
                                    'emitted' => trans('nfse::general.invoices.filter_emitted'),
                                    'cancelled' => trans('nfse::general.invoices.filter_cancelled'),
                                    'processing' => trans('nfse::general.invoices.filter_processing'),
                                    default => (string) ($receipt->status ?? '—'),
                                };
                            @endphp
                            <tr class="group hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}" class="text-indigo-700 hover:underline">
                                        {{ $receipt->invoice?->number ?? $receipt->invoice?->document_number ?? ('#' . $receipt->invoice_id) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $receipt->invoice?->contact?->name ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ money($receipt->invoice?->amount ?? 0, default_currency(), true) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <form action="{{ route('nfse.invoices.refresh', $receipt->invoice_id) }}" method="POST" class="inline-flex">
                                            @csrf
                                            <button type="submit" title="{{ trans('nfse::general.invoices.refresh_status') }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-gray-600 hover:bg-gray-100">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M4 4v5h.58A6.5 6.5 0 1110 16.5a6.46 6.46 0 01-4.6-1.9l-1.42 1.42A8.46 8.46 0 0010 18.5a8.5 8.5 0 10-7.88-5.5H0l4-4z" />
                                                </svg>
                                                <span class="sr-only">{{ trans('nfse::general.invoices.refresh_status') }}</span>
                                            </button>
                                        </form>

                                        @if(($receipt->status ?? '') === 'emitted')
                                            <button
                                                type="button"
                                                title="{{ trans('nfse::general.invoices.cancel') }}"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-700 hover:bg-red-50"
                                                data-cancel-trigger="true"
                                                data-cancel-action="{{ route('nfse.invoices.cancel', $receipt->invoice_id) }}"
                                                data-cancel-label="{{ $receipt->invoice?->number ?? ('#' . $receipt->invoice_id) }}"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M6 8a1 1 0 011 1v6a1 1 0 102 0V9a1 1 0 112 0v6a1 1 0 102 0V9a1 1 0 112 0v6a3 3 0 11-6 0V9a1 1 0 10-2 0v6a3 3 0 11-6 0V9a1 1 0 011-1z" clip-rule="evenodd" />
                                                    <path d="M4 5a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1z" />
                                                </svg>
                                                <span class="sr-only">{{ trans('nfse::general.invoices.cancel') }}</span>
                                            </button>
                                        @endif

                                        @if(($receipt->status ?? '') === 'cancelled')
                                            <form action="{{ route('nfse.invoices.reemit', $receipt->invoice_id) }}" method="POST" onsubmit="return confirm('{{ trans('nfse::general.invoices.reemit_confirm') }}')" class="inline-flex">
                                                @csrf
                                                <button type="submit" title="{{ trans('nfse::general.invoices.reemit') }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-green-200 text-green-700 hover:bg-green-50">
                                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path d="M10 3a7 7 0 00-6.32 4H1l3 3 3-3H4.85A5 5 0 1110 15a1 1 0 100 2 7 7 0 000-14z" />
                                                    </svg>
                                                    <span class="sr-only">{{ trans('nfse::general.invoices.reemit') }}</span>
                                                </button>
                                            </form>
                                        @endif

                                        <a href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}" title="{{ trans('nfse::general.invoices.view') }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path d="M10 4.5c-4 0-7.33 2.33-9 5.5 1.67 3.17 5 5.5 9 5.5s7.33-2.33 9-5.5c-1.67-3.17-5-5.5-9-5.5zm0 9a3.5 3.5 0 110-7 3.5 3.5 0 010 7z" />
                                            </svg>
                                            <span class="sr-only">{{ trans('nfse::general.invoices.view') }}</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">{{ trans('general.no_records') }}</td>
                            </tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
            </div>
        </div>

        <x-pagination :items="$isPendingStatus ? $pendingInvoices : $receipts" />

        <div
            id="nfse-cancel-modal"
            class="fixed inset-0 z-[100] hidden"
            data-old-action="{{ old('cancel_invoice_action', '') }}"
            aria-hidden="true"
        >
            <div class="absolute inset-0 bg-slate-500/55 backdrop-blur-[1px] backdrop-brightness-75" data-cancel-close="true"></div>

            <div class="relative flex min-h-full items-center justify-center overflow-y-auto p-4">
                <div class="w-full max-w-2xl rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b px-5 py-4">
                        <h3 class="text-lg font-semibold text-gray-800">{{ trans('nfse::general.invoices.cancel_modal_title') }}</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-cancel-close="true">{{ trans('nfse::general.invoices.cancel_modal_close') }}</button>
                    </div>

                    <form id="nfse-cancel-form" method="POST" action="" class="p-5 space-y-4">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="cancel_invoice_action" id="cancel_invoice_action" value="{{ old('cancel_invoice_action', '') }}">
                        @php($cancelReasonOptions = trans('nfse::general.invoices.cancel_reason_options'))

                        <div>
                            <label for="cancel_reason" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.invoices.cancel_modal_reason') }}</label>
                            <select
                                id="cancel_reason"
                                name="cancel_reason"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                required
                            >
                                <option value="">{{ trans('nfse::general.invoices.cancel_modal_reason_select_placeholder') }}</option>
                                @if(is_array($cancelReasonOptions))
                                    @foreach($cancelReasonOptions as $reasonOption)
                                        <option value="{{ $reasonOption }}" @selected(old('cancel_reason', '') === $reasonOption)>{{ $reasonOption }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>

                        <div>
                            <label for="cancel_justification" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.invoices.cancel_modal_justification') }}</label>
                            <textarea
                                id="cancel_justification"
                                name="cancel_justification"
                                rows="4"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                placeholder="{{ trans('nfse::general.invoices.cancel_modal_justification_placeholder') }}"
                                maxlength="1000"
                                required
                            >{{ old('cancel_justification', '') }}</textarea>
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t pt-4">
                            <button type="button" class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm hover:bg-gray-200" data-cancel-close="true">
                                {{ trans('nfse::general.invoices.cancel_modal_close') }}
                            </button>
                            <button id="cancel-submit-button" type="submit" class="inline-flex items-center rounded px-3 py-2 text-sm font-medium transition-colors duration-150 bg-gray-300 text-gray-500 cursor-not-allowed opacity-70" disabled aria-disabled="true" aria-busy="false">
                                <svg id="cancel-submit-spinner" class="mr-2 hidden h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                <span id="cancel-submit-label">{{ trans('nfse::general.invoices.cancel_modal_submit') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const cookieFilters = @json($searchStringCookieFilters ?? []);

                // Keep native AkauntingSearch visual chips in sync after page reload.
                const hydrateSearchStringCookie = () => {
                    if (!cookieFilters || Object.keys(cookieFilters).length === 0) {
                        return;
                    }

                    const path = window.location.href.replace(window.location.search, '');
                    let searchStringCookie = {};

                    const readRawCookie = (name) => {
                        const cookies = document.cookie ? document.cookie.split('; ') : [];

                        for (const item of cookies) {
                            const separatorIndex = item.indexOf('=');

                            if (separatorIndex === -1) {
                                continue;
                            }

                            const key = item.slice(0, separatorIndex);
                            const value = item.slice(separatorIndex + 1);

                            if (key === name) {
                                return decodeURIComponent(value);
                            }
                        }

                        return null;
                    };

                    try {
                        const rawCookie = (typeof Cookies !== 'undefined' && typeof Cookies.get === 'function')
                            ? Cookies.get('search-string')
                            : readRawCookie('search-string');
                        searchStringCookie = rawCookie ? JSON.parse(rawCookie) : {};
                    } catch (error) {
                        searchStringCookie = {};
                    }

                    searchStringCookie[path] = cookieFilters;

                    if (typeof Cookies !== 'undefined' && typeof Cookies.set === 'function') {
                        Cookies.set('search-string', searchStringCookie);
                    } else {
                        document.cookie = 'search-string=' + encodeURIComponent(JSON.stringify(searchStringCookie)) + '; path=/';
                    }
                };

                hydrateSearchStringCookie();

                const modal = document.getElementById('nfse-cancel-modal');
                const form = document.getElementById('nfse-cancel-form');
                const reasonSelect = document.getElementById('cancel_reason');
                const justificationInput = document.getElementById('cancel_justification');
                const submitButton = document.getElementById('cancel-submit-button');
                const submitSpinner = document.getElementById('cancel-submit-spinner');
                const submitLabel = document.getElementById('cancel-submit-label');
                const actionInput = document.getElementById('cancel_invoice_action');
                const submitDefaultLabel = @json((string) trans('nfse::general.invoices.cancel_modal_submit'));
                const submitLoadingLabel = @json((string) trans('nfse::general.invoices.cancel_modal_submitting'));
                let isSubmitting = false;

                if (!modal || !form || !reasonSelect || !justificationInput || !submitButton || !submitSpinner || !submitLabel || !actionInput) {
                    return;
                }

                const setSubmittingState = (submitting) => {
                    isSubmitting = submitting;

                    if (submitting) {
                        submitButton.disabled = true;
                        submitButton.setAttribute('aria-disabled', 'true');
                        submitButton.setAttribute('aria-busy', 'true');
                        submitButton.classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                        submitButton.classList.add('bg-gray-400', 'text-white', 'cursor-not-allowed', 'opacity-100');
                        submitSpinner.classList.remove('hidden');
                        submitLabel.textContent = submitLoadingLabel;

                        return;
                    }

                    submitButton.setAttribute('aria-busy', 'false');
                    submitSpinner.classList.add('hidden');
                    submitLabel.textContent = submitDefaultLabel;
                    updateSubmitState();
                };

                const updateSubmitState = () => {
                    if (isSubmitting) {
                        return;
                    }

                    const enabled = reasonSelect.value.trim() !== '' && justificationInput.value.trim() !== '';
                    submitButton.disabled = !enabled;
                    submitButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');

                    if (enabled) {
                        submitButton.classList.remove('bg-gray-300', 'bg-gray-400', 'text-gray-500', 'cursor-not-allowed', 'opacity-70');
                        submitButton.classList.add('bg-red-600', 'text-white', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');

                        return;
                    }

                    submitButton.classList.remove('bg-red-600', 'text-white', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                    submitButton.classList.add('bg-gray-300', 'text-gray-500', 'cursor-not-allowed', 'opacity-70');
                };

                const openModal = (actionUrl) => {
                    if (!actionUrl) {
                        return;
                    }

                    form.action = actionUrl;
                    actionInput.value = actionUrl;
                    modal.classList.remove('hidden');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('overflow-hidden');
                    updateSubmitState();
                    reasonSelect.focus();
                };

                const closeModal = () => {
                    if (isSubmitting) {
                        return;
                    }

                    modal.classList.add('hidden');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('overflow-hidden');
                };

                document.querySelectorAll('[data-cancel-trigger="true"]').forEach((button) => {
                    button.addEventListener('click', () => {
                        openModal(button.getAttribute('data-cancel-action'));
                    });
                });

                modal.querySelectorAll('[data-cancel-close="true"]').forEach((button) => {
                    button.addEventListener('click', closeModal);
                });

                reasonSelect.addEventListener('change', updateSubmitState);
                justificationInput.addEventListener('input', updateSubmitState);
                form.addEventListener('submit', () => {
                    if (isSubmitting) {
                        return;
                    }

                    setSubmittingState(true);
                });

                if (modal.getAttribute('data-old-action')) {
                    openModal(modal.getAttribute('data-old-action'));
                }
            })();
        </script>
    </x-slot>

    <x-script folder="common" file="documents" />
</x-layouts.admin>
