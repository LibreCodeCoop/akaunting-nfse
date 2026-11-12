{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.dashboard.title') }}</x-slot>

    <x-slot name="content">
        <div class="mb-4 flex flex-wrap gap-2">
            <a href="{{ route('nfse.ledger.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
                {{ trans('nfse::general.ledger.title') }}
            </a>
            <a href="{{ route('nfse.bulk.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.bulk.title') }}
            </a>
            @can('read-nfse-settings')
            <a href="{{ route('nfse.settings.edit') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_settings') }}
            </a>
            @endcan
        </div>

        <div id="nfse-dashboard"
            data-read-url="{{ route('nfse.dashboard.data', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all')) }}"
            data-empty-message="{{ trans('nfse::general.dashboard.no_recent_receipts') }}"
            data-error-message="{{ trans('nfse::general.nfse_refresh_failed') }}"
            data-invoice-label="{{ trans('nfse::general.dashboard.open') }}"
            data-sandbox-label="{{ trans('nfse::general.dashboard.sandbox_on') }}"
            data-production-label="{{ trans('nfse::general.dashboard.sandbox_off') }}">
            <p id="nfse-dashboard-feedback" role="status" aria-live="polite" tabindex="-1" hidden></p>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.total_receipts') }}</p>
                <p class="text-2xl font-semibold"><span id="nfse-dashboard-total">&mdash;</span></p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.emitted') }}</p>
                <p class="text-2xl font-semibold text-green-700"><span id="nfse-dashboard-emitted">&mdash;</span></p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.cancelled') }}</p>
                <p class="text-2xl font-semibold text-red-700"><span id="nfse-dashboard-cancelled">&mdash;</span></p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.environment') }}</p>
                <p class="text-lg font-semibold"><span id="nfse-dashboard-environment">&mdash;</span></p>
            </div>
        </div>

        <section class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="mb-3 flex flex-wrap justify-between items-center gap-2">
                <h2 class="text-lg font-semibold">{{ trans('nfse::general.dashboard.recent_receipts') }}</h2>
                <x-link href="{{ route('nfse.invoices.index') }}">{{ trans('nfse::general.dashboard.view_all') }}</x-link>
            </div>
            @php
                $searchStringFilters = [
                    [
                        'key' => 'status',
                        'value' => trans_choice('general.statuses', 1),
                        'type' => 'select',
                        'multiple' => true,
                        'values' => [
                            'emitted' => trans('nfse::general.ledger.status_emitted'),
                            'processing' => trans('nfse::general.ledger.status_processing'),
                            'cancelled' => trans('nfse::general.ledger.status_cancelled'),
                            'substituted' => trans('nfse::general.ledger.status_substituted'),
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
            <x-form method="GET" action="{{ route('nfse.dashboard.index') }}">
                <x-search-string :filters="$searchStringFilters" />
            </x-form>
            @php
                $sortUrl = static function (string $column) use ($filters): string {
                    return route('nfse.dashboard.index', array_filter([
                        'search' => $filters['search'],
                        'sort' => $column,
                        'direction' => $filters['sort'] === $column && $filters['direction'] === 'asc' ? 'desc' : 'asc',
                        // Preserve filters from URLs generated by the previous dashboard.
                        'status' => request()->has('status') ? request()->query('status') : null,
                        'from' => request()->has('from') ? request()->query('from') : null,
                        'to' => request()->has('to') ? request()->query('to') : null,
                    ], static fn ($value): bool => is_string($value) && $value !== ''));
                };
            @endphp
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="border-b border-gray-200">
                        <tr>
                            @foreach ([
                                'nfse_number' => trans('nfse::general.dashboard.number'),
                                'customer' => trans('nfse::general.invoices.customer'),
                                'data_emissao' => trans('nfse::general.dashboard.date'),
                                'amount' => trans('general.amount'),
                                'status' => trans_choice('general.statuses', 1),
                            ] as $column => $label)
                                <th scope="col" class="px-3 py-3 whitespace-nowrap {{ $column === 'amount' ? 'text-right' : 'text-left' }}">
                                    <a href="{{ $sortUrl($column) }}" class="text-gray-600 hover:underline" aria-label="{{ $label }}: {{ trans('nfse::general.dashboard.sort') }}">
                                        {{ $label }}
                                        @if ($filters['sort'] === $column)
                                            <span aria-hidden="true">{{ $filters['direction'] === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </a>
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-right">{{ trans('general.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody id="nfse-dashboard-receipts" class="divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </section>
        </div>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/dashboard-browser.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/dashboard-browser.js'))) }}" data-nfse-dashboard="true"></script>
    </x-slot>
</x-layouts.admin>
