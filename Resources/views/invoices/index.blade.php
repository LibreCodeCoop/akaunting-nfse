{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.invoices.title') }}</x-slot>

    <x-slot name="buttons">
        <form id="refresh-all-form" action="{{ route('nfse.invoices.refresh-all') }}" method="POST" class="inline-block" data-loading-label="{{ trans('nfse::general.invoices.refresh_all_statuses_loading') }}">
            @csrf
            <x-button kind="primary" id="index-more-actions-refresh-nfse-invoices" type="submit">
                {{ trans('nfse::general.invoices.refresh_all_statuses') }}
            </x-button>
        </form>

        <x-link href="{{ route('nfse.settings.edit') }}" id="index-more-actions-open-nfse-settings">
            {{ trans('nfse::general.go_to_settings') }}
        </x-link>
    </x-slot>

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

        @if(session('info'))
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4">
                {{ session('info') }}
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
            $selectedStatuses = $currentStatus === 'all'
                ? ['all']
                : array_values(array_filter(array_map('trim', explode(',', (string) $currentStatus))));
            $showsPendingRows = $currentStatus === 'pending' || in_array('pending', $selectedStatuses, true);
            $showsReceiptRows = $currentStatus === 'all' || array_values(array_filter($selectedStatuses, fn ($item) => $item !== 'pending')) !== [];
            $isPendingOnlyStatus = $showsPendingRows && !$showsReceiptRows;
            $isMixedStatusListing = $showsPendingRows && $showsReceiptRows;
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

        <x-form method="GET" action="{{ route($listingRouteName) }}">
            <x-search-string :filters="$searchStringFilters" />
        </x-form>

        @if($showsPendingRows && !$isReady)
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

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm text-gray-600">
                            <div class="font-medium"><x-sortablelink column="due_at" title="{{ trans('invoices.due_date') }}" /></div>
                            <div class="font-normal"><x-sortablelink column="issued_at" title="{{ trans('invoices.invoice_date') }}" /></div>
                        </th>
                        <th class="px-4 py-3 text-left text-sm text-gray-600">
                            <div class="font-medium"><x-sortablelink column="customer" title="{{ trans('nfse::general.invoices.customer') }}" /></div>
                            <div class="font-normal"><x-sortablelink column="document_number" title="{{ trans_choice('general.numbers', 1) }}" /></div>
                        </th>
                        <th class="px-4 py-3 text-left text-sm text-gray-600"><x-sortablelink column="amount" title="{{ trans('general.amount') }}" /></th>
                        <th class="px-4 py-3 text-left text-sm text-gray-600"><x-sortablelink column="status" title="{{ trans_choice('general.statuses', 1) }}" /></th>
                        <th class="px-4 py-3 text-right text-sm font-medium text-gray-600">{{ trans('general.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php
                        $pendingRowsRendered = false;
                        $receiptRowsRendered = false;
                    @endphp
                    @if($showsPendingRows)
                        @forelse($pendingInvoices as $invoice)
                            @php $pendingRowsRendered = true; @endphp
                            <tr class="group hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-bold">
                                        @if(!empty($invoice->due_at))
                                            <x-date :date="$invoice->due_at" function="diffForHumans" />
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        @if(!empty($invoice->issued_at))
                                            <x-date :date="$invoice->issued_at" />
                                        @else
                                            —
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-800">{{ $invoice->contact?->name ?? '—' }}</p>
                                    <a href="{{ route('invoices.show', $invoice) }}" class="mt-1 block">
                                        <span class="border-black border-b border-dashed">
                                            {{ $invoice->number ?? $invoice->document_number ?? ('#' . $invoice->id) }}
                                        </span>
                                    </a>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ money($invoice->amount, default_currency(), true) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700">
                                        {{ trans('nfse::general.invoices.filter_pending') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="relative inline-flex items-center">
                                        <div class="pointer-events-none absolute right-10 top-1/2 z-20 hidden w-72 -translate-y-1/2 rounded-lg border border-indigo-100 bg-white p-3 text-left text-xs text-gray-600 shadow-xl group-hover:block" data-row-quick-view="true">
                                            <p class="font-semibold text-gray-800">{{ $invoice->number ?? $invoice->document_number ?? ('#' . $invoice->id) }}</p>
                                            <p class="mt-1">{{ $invoice->contact?->name ?? '—' }}</p>
                                            <p class="mt-2">{{ trans('general.amount') }}: {{ money($invoice->amount, default_currency(), true) }}</p>
                                            <p>{{ trans('general.status') }}: {{ trans('nfse::general.invoices.filter_pending') }}</p>
                                        </div>

                                        <form
                                            action="{{ route('nfse.invoices.emit', $invoice) }}"
                                            method="POST"
                                            class="inline-flex"
                                            data-emit-form="true"
                                            data-preview-url="{{ route('nfse.invoices.service-preview', $invoice) }}"
                                            data-emit-confirm-label="{{ trans('nfse::general.invoices.emit_now') }}"
                                            onsubmit="
                                                if (this.dataset.emitConfirmed === '1') {
                                                    delete this.dataset.emitConfirmed;
                                                    return true;
                                                }
                                                event.preventDefault();
                                                (async (form) => {
                                                    const modal = document.getElementById('nfse-emit-modal');
                                                    const missingItemsContainer = document.getElementById('nfse-emit-missing-items');
                                                    const missingItemsHint = document.getElementById('nfse-emit-missing-items-hint');
                                                    const confirmButton = document.getElementById('nfse-emit-confirm-button');
                                                    const descriptionField = document.getElementById('nfse_emit_description');
                                                    const confirmInput = form.querySelector('[data-emit-confirm-default]');
                                                    const assignmentsInput = form.querySelector('[data-emit-assignments]');
                                                    const descriptionInput = form.querySelector('[data-emit-description-input]');
                                                    const previewUrl = form.getAttribute('data-preview-url');
                                                    const confirmLabel = form.getAttribute('data-emit-confirm-label') || '{{ trans('nfse::general.invoices.emit_now') }}';

                                                    if (!modal || !missingItemsContainer || !confirmButton || !descriptionField || !confirmInput || !assignmentsInput || !descriptionInput) {
                                                        form.dataset.emitConfirmed = '1';
                                                        form.submit();
                                                        return;
                                                    }

                                                    if (!form.id) {
                                                        form.id = 'nfse-emit-form-' + Math.random().toString(36).slice(2);
                                                    }

                                                    confirmInput.value = '0';
                                                    assignmentsInput.value = '';
                                                    descriptionInput.value = '';
                                                    missingItemsContainer.innerHTML = '';
                                                    missingItemsHint?.classList.add('hidden');

                                                    const openModal = (suggestedDescription, missingItems, availableServices, defaultServiceId) => {
                                                        missingItemsContainer.innerHTML = '';
                                                        missingItemsHint?.classList.toggle('hidden', missingItems.length === 0);

                                                        missingItems.forEach((item) => {
                                                            const wrapper = document.createElement('div');
                                                            wrapper.className = 'grid grid-cols-1 md:grid-cols-3 gap-2 items-center';

                                                            const label = document.createElement('label');
                                                            label.className = 'text-sm font-medium text-gray-700 md:col-span-1';
                                                            label.textContent = item.name ?? 'Item';

                                                            const select = document.createElement('select');
                                                            select.className = 'md:col-span-2 w-full border rounded px-3 py-2 text-sm';
                                                            select.setAttribute('data-item-id', String(item.id ?? '0'));

                                                            availableServices.forEach((service) => {
                                                                const option = document.createElement('option');
                                                                option.value = String(service.id ?? '');
                                                                option.textContent = String(service.label ?? '');
                                                                if (String(service.id ?? '') === String(defaultServiceId ?? '')) {
                                                                    option.selected = true;
                                                                }
                                                                select.appendChild(option);
                                                            });

                                                            wrapper.appendChild(label);
                                                            wrapper.appendChild(select);
                                                            missingItemsContainer.appendChild(wrapper);
                                                        });

                                                        modal.dataset.currentFormId = form.id;
                                                        confirmButton.textContent = confirmLabel;
                                                        descriptionField.value = suggestedDescription || '';
                                                        modal.classList.remove('hidden');
                                                        modal.setAttribute('aria-hidden', 'false');
                                                        document.body.classList.add('overflow-hidden');
                                                    };

                                                    if (!previewUrl) {
                                                        openModal(descriptionInput.value || '', [], [], 0);
                                                        return;
                                                    }

                                                    try {
                                                        const response = await fetch(previewUrl, { headers: { Accept: 'application/json' } });
                                                        if (!response.ok) {
                                                            form.dataset.emitConfirmed = '1';
                                                            form.submit();
                                                            return;
                                                        }

                                                        const payload = await response.json();
                                                        const missingItems = Array.isArray(payload.missing_items) ? payload.missing_items : [];
                                                        const availableServices = Array.isArray(payload.available_services) ? payload.available_services : [];
                                                        const defaultServiceId = payload.default_service_id ?? 0;
                                                        const suggestedDescription = typeof payload.suggested_description === 'string' ? payload.suggested_description : '';

                                                        if (missingItems.length > 0 && availableServices.length === 0) {
                                                            form.dataset.emitConfirmed = '1';
                                                            form.submit();
                                                            return;
                                                        }

                                                        openModal(suggestedDescription, missingItems, availableServices, defaultServiceId);
                                                    } catch {
                                                        form.dataset.emitConfirmed = '1';
                                                        form.submit();
                                                    }
                                                })(this);
                                                return false;
                                            "
                                        >
                                            @csrf
                                            <input type="hidden" name="nfse_confirm_default_service" value="0" data-emit-confirm-default>
                                            <input type="hidden" name="nfse_item_service_assignments" value="" data-emit-assignments>
                                            <input type="hidden" name="nfse_discriminacao_custom" value="" data-emit-description-input>
                                            <input type="hidden" name="nfse_send_email" value="0" data-emit-email-send-input>
                                            <input type="hidden" name="nfse_email_to" value="" data-emit-email-to-input>
                                            <input type="hidden" name="nfse_email_subject" value="" data-emit-email-subject-input>
                                            <input type="hidden" name="nfse_email_body" value="" data-emit-email-body-input>
                                            <input type="hidden" name="nfse_email_attach_danfse" value="1" data-emit-email-attach-danfse-input>
                                            <input type="hidden" name="nfse_email_attach_xml" value="1" data-emit-email-attach-xml-input>
                                            <input type="hidden" name="nfse_email_save_default" value="0" data-emit-email-save-default-input>
                                            <button
                                                type="submit"
                                                @if(!$isReady) disabled @endif
                                                title="{{ trans('nfse::general.invoices.emit_now') }}"
                                                data-emit-trigger="true"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded border @if($isReady) border-indigo-200 text-indigo-700 hover:bg-indigo-50 @else border-gray-300 text-gray-400 cursor-not-allowed @endif"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M4 10h8.17l-2.58-2.59L11 6l5 5-5 5-1.41-1.41L12.17 12H4v-2z" />
                                                </svg>
                                                <span class="sr-only">{{ trans('nfse::general.invoices.emit_now') }}</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                        @endforelse
                    @endif

                    @if($showsReceiptRows)
                        @forelse($receipts as $receipt)
                            @php $receiptRowsRendered = true; @endphp
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
                                    <div class="font-bold">
                                        @if(!empty($receipt->invoice?->due_at))
                                            <x-date :date="$receipt->invoice->due_at" function="diffForHumans" />
                                        @elseif(!empty($receipt->data_emissao))
                                            <x-date :date="$receipt->data_emissao" function="diffForHumans" />
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        @if(!empty($receipt->invoice?->issued_at))
                                            <x-date :date="$receipt->invoice->issued_at" />
                                        @elseif(!empty($receipt->data_emissao))
                                            <x-date :date="$receipt->data_emissao" />
                                        @else
                                            —
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-800">{{ $receipt->invoice?->contact?->name ?? '—' }}</p>
                                    <a href="{{ route('invoices.show', $receipt->invoice_id) }}" class="mt-1 block">
                                        <span class="border-black border-b border-dashed">
                                            {{ $receipt->invoice?->number ?? $receipt->invoice?->document_number ?? ('#' . $receipt->invoice_id) }}
                                        </span>
                                    </a>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ money($receipt->invoice?->amount ?? 0, default_currency(), true) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="relative inline-flex items-center gap-1">
                                        <div class="pointer-events-none absolute right-28 top-1/2 z-20 hidden w-72 -translate-y-1/2 rounded-lg border border-indigo-100 bg-white p-3 text-left text-xs text-gray-600 shadow-xl group-hover:block" data-row-quick-view="true">
                                            <p class="font-semibold text-gray-800">{{ $receipt->invoice?->number ?? $receipt->invoice?->document_number ?? ('#' . $receipt->invoice_id) }}</p>
                                            <p class="mt-1">{{ $receipt->invoice?->contact?->name ?? '—' }}</p>
                                            <p class="mt-2">{{ trans('general.amount') }}: {{ money($receipt->invoice?->amount ?? 0, default_currency(), true) }}</p>
                                            <p>{{ trans('general.status') }}: {{ $statusLabel }}</p>
                                        </div>

                                        @if(($receipt->status ?? '') !== 'cancelled')
                                            <form action="{{ route('nfse.invoices.refresh', $receipt->invoice_id) }}" method="POST" class="inline-flex">
                                                @csrf
                                                <button type="submit" title="{{ trans('nfse::general.invoices.refresh_status') }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-gray-600 hover:bg-gray-100">
                                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path d="M4 4v5h.58A6.5 6.5 0 1110 16.5a6.46 6.46 0 01-4.6-1.9l-1.42 1.42A8.46 8.46 0 0010 18.5a8.5 8.5 0 10-7.88-5.5H0l4-4z" />
                                                    </svg>
                                                    <span class="sr-only">{{ trans('nfse::general.invoices.refresh_status') }}</span>
                                                </button>
                                            </form>
                                        @endif

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
                                            <form
                                                action="{{ route('nfse.invoices.reemit', $receipt->invoice_id) }}"
                                                method="POST"
                                                class="inline-flex"
                                                data-emit-form="true"
                                                data-preview-url="{{ route('nfse.invoices.service-preview', $receipt->invoice_id) }}"
                                                data-emit-confirm-label="{{ trans('nfse::general.invoices.reemit') }}"
                                                onsubmit="
                                                    if (this.dataset.emitConfirmed === '1') {
                                                        delete this.dataset.emitConfirmed;
                                                        return true;
                                                    }
                                                    event.preventDefault();
                                                    (async (form) => {
                                                        const modal = document.getElementById('nfse-emit-modal');
                                                        const missingItemsContainer = document.getElementById('nfse-emit-missing-items');
                                                        const missingItemsHint = document.getElementById('nfse-emit-missing-items-hint');
                                                        const confirmButton = document.getElementById('nfse-emit-confirm-button');
                                                        const descriptionField = document.getElementById('nfse_emit_description');
                                                        const confirmInput = form.querySelector('[data-emit-confirm-default]');
                                                        const assignmentsInput = form.querySelector('[data-emit-assignments]');
                                                        const descriptionInput = form.querySelector('[data-emit-description-input]');
                                                        const previewUrl = form.getAttribute('data-preview-url');
                                                        const confirmLabel = form.getAttribute('data-emit-confirm-label') || '{{ trans('nfse::general.invoices.reemit') }}';

                                                        if (!modal || !missingItemsContainer || !confirmButton || !descriptionField || !confirmInput || !assignmentsInput || !descriptionInput) {
                                                            form.dataset.emitConfirmed = '1';
                                                            form.submit();
                                                            return;
                                                        }

                                                        if (!form.id) {
                                                            form.id = 'nfse-emit-form-' + Math.random().toString(36).slice(2);
                                                        }

                                                        confirmInput.value = '0';
                                                        assignmentsInput.value = '';
                                                        descriptionInput.value = '';
                                                        missingItemsContainer.innerHTML = '';
                                                        missingItemsHint?.classList.add('hidden');

                                                        const openModal = (suggestedDescription, missingItems, availableServices, defaultServiceId) => {
                                                            missingItemsContainer.innerHTML = '';
                                                            missingItemsHint?.classList.toggle('hidden', missingItems.length === 0);

                                                            missingItems.forEach((item) => {
                                                                const wrapper = document.createElement('div');
                                                                wrapper.className = 'grid grid-cols-1 md:grid-cols-3 gap-2 items-center';

                                                                const label = document.createElement('label');
                                                                label.className = 'text-sm font-medium text-gray-700 md:col-span-1';
                                                                label.textContent = item.name ?? 'Item';

                                                                const select = document.createElement('select');
                                                                select.className = 'md:col-span-2 w-full border rounded px-3 py-2 text-sm';
                                                                select.setAttribute('data-item-id', String(item.id ?? '0'));

                                                                availableServices.forEach((service) => {
                                                                    const option = document.createElement('option');
                                                                    option.value = String(service.id ?? '');
                                                                    option.textContent = String(service.label ?? '');
                                                                    if (String(service.id ?? '') === String(defaultServiceId ?? '')) {
                                                                        option.selected = true;
                                                                    }
                                                                    select.appendChild(option);
                                                                });

                                                                wrapper.appendChild(label);
                                                                wrapper.appendChild(select);
                                                                missingItemsContainer.appendChild(wrapper);
                                                            });

                                                            modal.dataset.currentFormId = form.id;
                                                            confirmButton.textContent = confirmLabel;
                                                            descriptionField.value = suggestedDescription || '';
                                                            modal.classList.remove('hidden');
                                                            modal.setAttribute('aria-hidden', 'false');
                                                            document.body.classList.add('overflow-hidden');
                                                        };

                                                        if (!previewUrl) {
                                                            openModal(descriptionInput.value || '', [], [], 0);
                                                            return;
                                                        }

                                                        try {
                                                            const response = await fetch(previewUrl, { headers: { Accept: 'application/json' } });
                                                            if (!response.ok) {
                                                                form.dataset.emitConfirmed = '1';
                                                                form.submit();
                                                                return;
                                                            }

                                                            const payload = await response.json();
                                                            const missingItems = Array.isArray(payload.missing_items) ? payload.missing_items : [];
                                                            const availableServices = Array.isArray(payload.available_services) ? payload.available_services : [];
                                                            const defaultServiceId = payload.default_service_id ?? 0;
                                                            const suggestedDescription = typeof payload.suggested_description === 'string' ? payload.suggested_description : '';

                                                            if (missingItems.length > 0 && availableServices.length === 0) {
                                                                form.dataset.emitConfirmed = '1';
                                                                form.submit();
                                                                return;
                                                            }

                                                            openModal(suggestedDescription, missingItems, availableServices, defaultServiceId);
                                                        } catch {
                                                            form.dataset.emitConfirmed = '1';
                                                            form.submit();
                                                        }
                                                    })(this);
                                                    return false;
                                                "
                                            >
                                                @csrf
                                                <input type="hidden" name="nfse_confirm_default_service" value="0" data-emit-confirm-default>
                                                <input type="hidden" name="nfse_item_service_assignments" value="" data-emit-assignments>
                                                <input type="hidden" name="nfse_discriminacao_custom" value="" data-emit-description-input>
                                                <input type="hidden" name="nfse_send_email" value="0" data-emit-email-send-input>
                                                <input type="hidden" name="nfse_email_to" value="" data-emit-email-to-input>
                                                <input type="hidden" name="nfse_email_subject" value="" data-emit-email-subject-input>
                                                <input type="hidden" name="nfse_email_body" value="" data-emit-email-body-input>
                                                <input type="hidden" name="nfse_email_attach_danfse" value="1" data-emit-email-attach-danfse-input>
                                                <input type="hidden" name="nfse_email_attach_xml" value="1" data-emit-email-attach-xml-input>
                                                <input type="hidden" name="nfse_email_save_default" value="0" data-emit-email-save-default-input>
                                                <button type="submit" title="{{ trans('nfse::general.invoices.reemit') }}" data-emit-trigger="true" class="inline-flex h-8 w-8 items-center justify-center rounded border border-green-200 text-green-700 hover:bg-green-50">
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
                        @endforelse
                    @endif

                    @if(!$pendingRowsRendered && !$receiptRowsRendered)
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                {{ $showsPendingRows ? trans('nfse::general.invoices.no_pending') : trans('general.no_records') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if($isPendingOnlyStatus)
            <x-pagination :items="$pendingInvoices" />
        @elseif($isMixedStatusListing)
            <div class="space-y-4">
                @if($pendingInvoices)
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ trans('nfse::general.invoices.filter_pending') }}</p>
                        <x-pagination :items="$pendingInvoices" />
                    </div>
                @endif
                @if($receipts)
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">{{ trans('general.results') }}</p>
                        <x-pagination :items="$receipts" />
                    </div>
                @endif
            </div>
        @else
            <x-pagination :items="$receipts" />
        @endif

        <div id="nfse-emit-modal" class="fixed inset-0 z-[100] hidden" aria-hidden="true" data-default-confirm-label="{{ trans('nfse::general.invoices.emit_now') }}">
            <div class="absolute inset-0 bg-slate-500/55 backdrop-blur-[1px] backdrop-brightness-75" data-emit-close="true" onclick="const modal = document.getElementById('nfse-emit-modal'); const items = document.getElementById('nfse-emit-missing-items'); const hint = document.getElementById('nfse-emit-missing-items-hint'); const description = document.getElementById('nfse_emit_description'); const confirm = document.getElementById('nfse-emit-confirm-button'); if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden', 'true'); modal.dataset.currentFormId = ''; document.body.classList.remove('overflow-hidden'); } if (items) { items.innerHTML = ''; } if (hint) { hint.classList.add('hidden'); } if (description) { description.value = ''; } if (confirm && modal?.dataset.defaultConfirmLabel) { confirm.textContent = modal.dataset.defaultConfirmLabel; } return false;"></div>

            <div class="relative flex min-h-full items-center justify-center overflow-y-auto p-4">
                <div class="w-full max-w-2xl rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b px-5 py-4">
                        <h3 id="nfse-emit-modal-title" class="text-lg font-semibold text-gray-800">{{ trans('nfse::general.invoices.emit_modal_title') }}</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-emit-close="true" onclick="const modal = document.getElementById('nfse-emit-modal'); const items = document.getElementById('nfse-emit-missing-items'); const hint = document.getElementById('nfse-emit-missing-items-hint'); const description = document.getElementById('nfse_emit_description'); const confirm = document.getElementById('nfse-emit-confirm-button'); if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden', 'true'); modal.dataset.currentFormId = ''; document.body.classList.remove('overflow-hidden'); } if (items) { items.innerHTML = ''; } if (hint) { hint.classList.add('hidden'); } if (description) { description.value = ''; } if (confirm && modal?.dataset.defaultConfirmLabel) { confirm.textContent = modal.dataset.defaultConfirmLabel; } return false;">{{ trans('nfse::general.invoices.cancel_modal_close') }}</button>
                    </div>

                    <div class="p-5 space-y-4">
                        <p id="nfse-emit-missing-items-hint" class="hidden text-sm text-amber-700">{{ trans('nfse::general.invoices.emit_modal_missing_items_hint') }}</p>
                        <div id="nfse-emit-missing-items" class="space-y-3"></div>

                        <div>
                            <label for="nfse_emit_description" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_description') }}</label>
                            <textarea
                                id="nfse_emit_description"
                                rows="5"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                placeholder="{{ trans('nfse::general.invoices.emit_modal_description_placeholder') }}"
                            ></textarea>
                            <p class="mt-2 text-xs text-gray-500">{{ trans('nfse::general.invoices.emit_modal_description_help') }}</p>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                            <div class="flex items-center gap-3">
                                <label for="nfse_emit_send_email" class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center" aria-label="{{ trans('nfse::general.invoices.emit_modal_send_email') }}">
                                    <input id="nfse_emit_send_email" type="checkbox" class="sr-only peer">
                                    <div class="block h-7 w-12 rounded-full bg-green-200 transition-colors duration-200 peer-checked:bg-green"></div>
                                    <div class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 peer-checked:translate-x-5"></div>
                                </label>
                                <div>
                                    <p class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_send_email') }}</p>
                                    <p class="text-xs text-gray-500">{{ trans('nfse::general.invoices.emit_modal_send_email_hint') }}</p>
                                </div>
                            </div>

                            <div id="nfse_emit_email_fields" class="hidden space-y-3">
                            <div>
                                <label for="nfse_emit_email_to" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_to') }}</label>
                                <input id="nfse_emit_email_to" type="email" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" value="">
                            </div>

                            <div>
                                <label for="nfse_emit_email_subject" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_subject') }}</label>
                                <input id="nfse_emit_email_subject" type="text" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" value="">
                            </div>

                            <div>
                                <label for="nfse_emit_email_body" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_body') }}</label>
                                <textarea id="nfse_emit_email_body" rows="4" class="w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
                                <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.invoices.emit_modal_email_body_help') }}</p>
                            </div>

                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input id="nfse_emit_attach_danfse" type="checkbox" class="rounded border-gray-300" checked>
                                <span>{{ trans('nfse::general.invoices.emit_modal_email_attach_danfse') }}</span>
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input id="nfse_emit_attach_xml" type="checkbox" class="rounded border-gray-300" checked>
                                <span>{{ trans('nfse::general.invoices.emit_modal_email_attach_xml') }}</span>
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input id="nfse_emit_save_default" type="checkbox" class="rounded border-gray-300">
                                <span>{{ trans('nfse::general.invoices.emit_modal_email_save_default') }}</span>
                            </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
                        <button type="button" class="inline-flex items-center px-3 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-100" data-emit-close="true" onclick="const modal = document.getElementById('nfse-emit-modal'); const items = document.getElementById('nfse-emit-missing-items'); const hint = document.getElementById('nfse-emit-missing-items-hint'); const description = document.getElementById('nfse_emit_description'); const confirm = document.getElementById('nfse-emit-confirm-button'); if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden', 'true'); modal.dataset.currentFormId = ''; document.body.classList.remove('overflow-hidden'); } if (items) { items.innerHTML = ''; } if (hint) { hint.classList.add('hidden'); } if (description) { description.value = ''; } if (confirm && modal?.dataset.defaultConfirmLabel) { confirm.textContent = modal.dataset.defaultConfirmLabel; } return false;">
                            {{ trans('general.cancel') }}
                        </button>
                        <button type="button" id="nfse-emit-confirm-button" class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700" onclick="const modal = document.getElementById('nfse-emit-modal'); const formId = modal?.dataset.currentFormId || ''; const form = formId ? document.getElementById(formId) : null; const descriptionField = document.getElementById('nfse_emit_description'); const itemsContainer = document.getElementById('nfse-emit-missing-items'); const confirmInput = form?.querySelector('[data-emit-confirm-default]'); const assignmentsInput = form?.querySelector('[data-emit-assignments]'); const descriptionInput = form?.querySelector('[data-emit-description-input]'); if (!form || !confirmInput || !assignmentsInput || !descriptionInput) { return false; } const assignments = {}; itemsContainer?.querySelectorAll('select[data-item-id]').forEach((select) => { const itemId = select.getAttribute('data-item-id'); const serviceId = select.value; if (itemId && serviceId) { assignments[itemId] = serviceId; } }); confirmInput.value = '1'; assignmentsInput.value = JSON.stringify(assignments); descriptionInput.value = descriptionField?.value || ''; form.dataset.emitConfirmed = '1'; if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden', 'true'); modal.dataset.currentFormId = ''; document.body.classList.remove('overflow-hidden'); } if (itemsContainer) { itemsContainer.innerHTML = ''; } document.getElementById('nfse-emit-missing-items-hint')?.classList.add('hidden'); form.submit(); return false;">
                            {{ trans('nfse::general.invoices.emit_now') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

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

                // When the AkauntingSearch "×" button is clicked, it normally navigates to the
                // bare URL (no query params), which would trigger our server-side preference
                // restore and bring back the old filter. Instead, intercept the click and
                // navigate to ?search= (explicit empty search), which the server treats as
                // "user explicitly cleared" — skipping the restore and saving default preferences.
                document.addEventListener('click', function (e) {
                    const clearBtn = e.target && e.target.closest('.clear');

                    if (!clearBtn || !clearBtn.closest('.js-search')) {
                        return;
                    }

                    e.stopImmediatePropagation();
                    e.preventDefault();

                    if (typeof Cookies !== 'undefined' && typeof Cookies.remove === 'function') {
                        Cookies.remove('search-string');
                    }

                    const basePath = window.location.href.replace(window.location.search, '');
                    window.location.href = basePath + '?search=';
                }, true);

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

                const emitModal = document.getElementById('nfse-emit-modal');
                const emitModalMissingItems = document.getElementById('nfse-emit-missing-items');
                const emitModalMissingItemsHint = document.getElementById('nfse-emit-missing-items-hint');
                const emitModalConfirmButton = document.getElementById('nfse-emit-confirm-button');
                const emitModalDescriptionInput = document.getElementById('nfse_emit_description');
                const emitModalSendEmailInput = document.getElementById('nfse_emit_send_email');
                const emitModalEmailFields = document.getElementById('nfse_emit_email_fields');
                const emitModalEmailToInput = document.getElementById('nfse_emit_email_to');
                const emitModalEmailSubjectInput = document.getElementById('nfse_emit_email_subject');
                const emitModalEmailBodyInput = document.getElementById('nfse_emit_email_body');
                const emitModalAttachDanfseInput = document.getElementById('nfse_emit_attach_danfse');
                const emitModalAttachXmlInput = document.getElementById('nfse_emit_attach_xml');
                const emitModalSaveDefaultInput = document.getElementById('nfse_emit_save_default');
                const emitModalDefaultConfirmLabel = @json((string) trans('nfse::general.invoices.emit_now'));
                let currentEmitForm = null;

                const refreshEmitEmailSection = () => {
                    if (emitModalEmailFields) {
                        emitModalEmailFields.classList.toggle('hidden', !emitModalSendEmailInput?.checked);
                    }
                };

                const applyEmailDefaults = (emailDefaults = {}) => {
                    if (emitModalSendEmailInput) {
                        emitModalSendEmailInput.checked = Boolean(emailDefaults.send_email);
                    }

                    if (emitModalEmailToInput) {
                        emitModalEmailToInput.value = typeof emailDefaults.recipient === 'string' ? emailDefaults.recipient : '';
                    }

                    if (emitModalEmailSubjectInput) {
                        emitModalEmailSubjectInput.value = typeof emailDefaults.subject === 'string' ? emailDefaults.subject : '';
                    }

                    if (emitModalEmailBodyInput) {
                        emitModalEmailBodyInput.value = typeof emailDefaults.body === 'string' ? emailDefaults.body : '';
                    }

                    if (emitModalAttachDanfseInput) {
                        emitModalAttachDanfseInput.checked = emailDefaults.attach_danfse !== false;
                    }

                    if (emitModalAttachXmlInput) {
                        emitModalAttachXmlInput.checked = emailDefaults.attach_xml !== false;
                    }

                    if (emitModalSaveDefaultInput) {
                        emitModalSaveDefaultInput.checked = false;
                    }

                    refreshEmitEmailSection();
                };

                const closeEmitModal = () => {
                    if (!emitModal) {
                        return;
                    }

                    emitModal.classList.add('hidden');
                    emitModal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('overflow-hidden');
                    currentEmitForm = null;

                    if (emitModalMissingItems) {
                        emitModalMissingItems.innerHTML = '';
                    }

                    if (emitModalMissingItemsHint) {
                        emitModalMissingItemsHint.classList.add('hidden');
                    }

                    if (emitModalDescriptionInput) {
                        emitModalDescriptionInput.value = '';
                    }

                    applyEmailDefaults({
                        send_email: false,
                        recipient: '',
                        subject: '',
                        body: '',
                        attach_danfse: true,
                        attach_xml: true,
                    });

                    if (emitModalConfirmButton) {
                        emitModalConfirmButton.textContent = emitModalDefaultConfirmLabel;
                    }
                };

                const openEmitModal = (confirmLabel, descriptionValue, emailDefaults = {}) => {
                    if (!emitModal) {
                        return;
                    }

                    if (emitModalConfirmButton) {
                        emitModalConfirmButton.textContent = confirmLabel || emitModalDefaultConfirmLabel;
                    }

                    if (emitModalDescriptionInput) {
                        emitModalDescriptionInput.value = descriptionValue || '';
                    }

                    applyEmailDefaults(emailDefaults);

                    emitModal.classList.remove('hidden');
                    emitModal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('overflow-hidden');
                };

                const renderMissingItemRows = (missingItems, availableServices, defaultServiceId) => {
                    if (!emitModalMissingItems) {
                        return;
                    }

                    emitModalMissingItems.innerHTML = '';

                    if (emitModalMissingItemsHint) {
                        emitModalMissingItemsHint.classList.toggle('hidden', missingItems.length === 0);
                    }

                    missingItems.forEach((item) => {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'grid grid-cols-1 md:grid-cols-3 gap-2 items-center';

                        const label = document.createElement('label');
                        label.className = 'text-sm font-medium text-gray-700 md:col-span-1';
                        label.textContent = item.name ?? 'Item';

                        const select = document.createElement('select');
                        select.className = 'md:col-span-2 w-full border rounded px-3 py-2 text-sm';
                        select.setAttribute('data-item-id', String(item.id ?? '0'));

                        availableServices.forEach((service) => {
                            const option = document.createElement('option');
                            option.value = String(service.id ?? '');
                            option.textContent = String(service.label ?? '');
                            if (String(service.id ?? '') === String(defaultServiceId ?? '')) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        });

                        wrapper.appendChild(label);
                        wrapper.appendChild(select);
                        emitModalMissingItems.appendChild(wrapper);
                    });
                };

                document.querySelectorAll('[data-emit-form="true"]').forEach((form) => {
                    const trigger = form.querySelector('[data-emit-trigger="true"]');
                    const confirmInput = form.querySelector('[data-emit-confirm-default]');
                    const assignmentsInput = form.querySelector('[data-emit-assignments]');
                    const descriptionInput = form.querySelector('[data-emit-description-input]');
                    const previewUrl = form.getAttribute('data-preview-url');
                    const confirmLabel = form.getAttribute('data-emit-confirm-label') || emitModalDefaultConfirmLabel;

                    if (!trigger || !confirmInput || !assignmentsInput || !descriptionInput) {
                        return;
                    }

                    trigger.addEventListener('click', async () => {
                        if (trigger.disabled) {
                            return;
                        }

                        confirmInput.value = '0';
                        assignmentsInput.value = '';
                        descriptionInput.value = '';

                        if (!previewUrl) {
                            form.submit();
                            return;
                        }

                        try {
                            const response = await fetch(previewUrl, { headers: { Accept: 'application/json' } });

                            if (!response.ok) {
                                form.submit();
                                return;
                            }

                            const payload = await response.json();
                            const missingItems = Array.isArray(payload.missing_items) ? payload.missing_items : [];
                            const availableServices = Array.isArray(payload.available_services) ? payload.available_services : [];
                            const defaultServiceId = payload.default_service_id ?? 0;
                            const suggestedDescription = typeof payload.suggested_description === 'string' ? payload.suggested_description : '';
                            const emailDefaults = typeof payload.email_defaults === 'object' && payload.email_defaults !== null
                                ? payload.email_defaults
                                : {};

                            if (missingItems.length > 0 && availableServices.length === 0) {
                                form.submit();
                                return;
                            }

                            currentEmitForm = form;
                            renderMissingItemRows(missingItems, availableServices, defaultServiceId);
                            openEmitModal(confirmLabel, suggestedDescription, emailDefaults);
                        } catch {
                            form.submit();
                        }
                    });
                });

                emitModal?.querySelectorAll('[data-emit-close="true"]').forEach((button) => {
                    button.addEventListener('click', closeEmitModal);
                });

                emitModalSendEmailInput?.addEventListener('change', refreshEmitEmailSection);

                emitModalConfirmButton?.addEventListener('click', () => {
                    if (!currentEmitForm) {
                        closeEmitModal();
                        return;
                    }

                    const confirmInput = currentEmitForm.querySelector('[data-emit-confirm-default]');
                    const assignmentsInput = currentEmitForm.querySelector('[data-emit-assignments]');
                    const descriptionInput = currentEmitForm.querySelector('[data-emit-description-input]');
                    const sendEmailInput = currentEmitForm.querySelector('[data-emit-email-send-input]');
                    const emailToInput = currentEmitForm.querySelector('[data-emit-email-to-input]');
                    const emailSubjectInput = currentEmitForm.querySelector('[data-emit-email-subject-input]');
                    const emailBodyInput = currentEmitForm.querySelector('[data-emit-email-body-input]');
                    const attachDanfseInput = currentEmitForm.querySelector('[data-emit-email-attach-danfse-input]');
                    const attachXmlInput = currentEmitForm.querySelector('[data-emit-email-attach-xml-input]');
                    const saveDefaultInput = currentEmitForm.querySelector('[data-emit-email-save-default-input]');
                    const assignmentRows = emitModalMissingItems?.querySelectorAll('select[data-item-id]') ?? [];
                    const assignments = {};

                    assignmentRows.forEach((select) => {
                        const itemId = select.getAttribute('data-item-id');
                        const serviceId = select.value;

                        if (itemId && serviceId) {
                            assignments[itemId] = serviceId;
                        }
                    });

                    if (confirmInput) {
                        confirmInput.value = '1';
                    }

                    if (assignmentsInput) {
                        assignmentsInput.value = JSON.stringify(assignments);
                    }

                    if (descriptionInput && emitModalDescriptionInput) {
                        descriptionInput.value = emitModalDescriptionInput.value;
                    }

                    if (sendEmailInput && emitModalSendEmailInput) {
                        sendEmailInput.value = emitModalSendEmailInput.checked ? '1' : '0';
                    }

                    if (emailToInput && emitModalEmailToInput) {
                        emailToInput.value = emitModalEmailToInput.value;
                    }

                    if (emailSubjectInput && emitModalEmailSubjectInput) {
                        emailSubjectInput.value = emitModalEmailSubjectInput.value;
                    }

                    if (emailBodyInput && emitModalEmailBodyInput) {
                        emailBodyInput.value = emitModalEmailBodyInput.value;
                    }

                    if (attachDanfseInput && emitModalAttachDanfseInput) {
                        attachDanfseInput.value = emitModalAttachDanfseInput.checked ? '1' : '0';
                    }

                    if (attachXmlInput && emitModalAttachXmlInput) {
                        attachXmlInput.value = emitModalAttachXmlInput.checked ? '1' : '0';
                    }

                    if (saveDefaultInput && emitModalSaveDefaultInput) {
                        saveDefaultInput.value = emitModalSaveDefaultInput.checked ? '1' : '0';
                    }

                    closeEmitModal();
                    currentEmitForm.submit();
                });

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

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;
                    const trigger = target ? target.closest('[data-cancel-trigger="true"]') : null;

                    if (!trigger) {
                        return;
                    }

                    event.preventDefault();
                    openModal(trigger.getAttribute('data-cancel-action'));
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
        <script>
                const refreshAllForm = document.getElementById('refresh-all-form');
                const refreshAllButton = document.getElementById('index-more-actions-refresh-nfse-invoices');

                if (refreshAllForm && refreshAllButton) {
                    const defaultLabel = refreshAllButton.textContent;
                    const loadingLabel = refreshAllForm.getAttribute('data-loading-label') || defaultLabel;

                    refreshAllForm.addEventListener('submit', () => {
                        refreshAllButton.setAttribute('disabled', 'disabled');
                        refreshAllButton.setAttribute('aria-busy', 'true');
                        refreshAllButton.textContent = loadingLabel;
                    });
                }

            (() => {
                const initCancelModal = () => {
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

                    if (!modal || !form || !reasonSelect || !justificationInput || !submitButton || !submitSpinner || !submitLabel || !actionInput) {
                        return;
                    }

                    if (modal.dataset.cancelBound === '1') {
                        return;
                    }

                    modal.dataset.cancelBound = '1';

                    let isSubmitting = false;

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
                        } else {
                            submitButton.classList.remove('bg-red-600', 'text-white', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                            submitButton.classList.add('bg-gray-300', 'text-gray-500', 'cursor-not-allowed', 'opacity-70');
                        }
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

                    document.addEventListener('click', (event) => {
                        const target = event.target instanceof Element ? event.target : null;

                        const trigger = target ? target.closest('[data-cancel-trigger="true"]') : null;
                        if (trigger) {
                            event.preventDefault();
                            openModal(trigger.getAttribute('data-cancel-action'));
                            return;
                        }

                        const closeTrigger = target ? target.closest('[data-cancel-close="true"]') : null;
                        if (closeTrigger) {
                            event.preventDefault();
                            closeModal();
                        }
                    });

                    reasonSelect.addEventListener('change', updateSubmitState);
                    justificationInput.addEventListener('input', updateSubmitState);
                    form.addEventListener('submit', () => {
                        if (isSubmitting) {
                            return;
                        }

                        isSubmitting = true;
                        submitButton.disabled = true;
                        submitButton.setAttribute('aria-disabled', 'true');
                        submitButton.setAttribute('aria-busy', 'true');
                        submitButton.classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                        submitButton.classList.add('bg-gray-400', 'text-white', 'cursor-not-allowed', 'opacity-100');
                        submitSpinner.classList.remove('hidden');
                        submitLabel.textContent = submitLoadingLabel;
                    });

                    submitLabel.textContent = submitDefaultLabel;
                    updateSubmitState();

                    if (modal.getAttribute('data-old-action')) {
                        openModal(modal.getAttribute('data-old-action'));
                    }
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initCancelModal, { once: true });
                } else {
                    initCancelModal();
                }
            })();
        </script>
    </x-slot>

    <x-script folder="common" file="documents" />
</x-layouts.admin>
