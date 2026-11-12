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

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.total_receipts') }}</p>
                <p class="text-2xl font-semibold">{{ $stats['total'] ?? 0 }}</p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.emitted') }}</p>
                <p class="text-2xl font-semibold text-green-700">{{ $stats['emitted'] ?? 0 }}</p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.cancelled') }}</p>
                <p class="text-2xl font-semibold text-red-700">{{ $stats['cancelled'] ?? 0 }}</p>
            </div>
            <div class="bg-white border rounded p-4">
                <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.environment') }}</p>
                <p class="text-lg font-semibold">{{ ($stats['sandbox_mode'] ?? true) ? trans('nfse::general.dashboard.sandbox_on') : trans('nfse::general.dashboard.sandbox_off') }}</p>
            </div>
        </div>

        <section class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="mb-3 flex flex-wrap justify-between items-center gap-2">
                <h2 class="text-lg font-semibold">{{ trans('nfse::general.dashboard.recent_receipts') }}</h2>
                <x-link href="{{ route('nfse.invoices.index') }}">{{ trans('nfse::general.dashboard.view_all') }}</x-link>
            </div>
            <x-form method="GET" action="{{ route('nfse.dashboard.index') }}">
                <div class="mb-4 flex flex-wrap items-end gap-2">
                    <label class="text-sm text-gray-600">
                        {{ trans('nfse::general.dashboard.search') }}
                        <input type="search" name="search" maxlength="100" value="{{ $filters['search'] }}"
                            class="mt-1 block rounded border border-gray-300 px-3 py-2 text-sm" />
                    </label>
                    <label class="text-sm text-gray-600">
                        {{ trans_choice('general.statuses', 1) }}
                        <select name="status" class="mt-1 block rounded border border-gray-300 px-3 py-2 text-sm">
                            @foreach (['all', 'emitted', 'processing', 'cancelled', 'substituted'] as $option)
                                <option value="{{ $option }}" @selected($filters['status'] === $option)>
                                    {{ trans('nfse::general.ledger.status_' . $option) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    @foreach (['from', 'to'] as $dateField)
                        <label class="text-sm text-gray-600">
                            {{ trans('nfse::general.ledger.' . $dateField) }}
                            <input type="date" name="{{ $dateField }}" value="{{ $filters[$dateField] }}"
                                class="mt-1 block rounded border border-gray-300 px-3 py-2 text-sm" />
                        </label>
                    @endforeach
                    <x-button type="submit">{{ trans('nfse::general.ledger.filter') }}</x-button>
                    <x-link href="{{ route('nfse.dashboard.index') }}">{{ trans('nfse::general.dashboard.clear_filters') }}</x-link>
                </div>
            </x-form>
            @php
                $sortUrl = static function (string $column) use ($filters): string {
                    return route('nfse.dashboard.index', array_merge(
                        array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'),
                        [
                            'sort' => $column,
                            'direction' => $filters['sort'] === $column && $filters['direction'] === 'asc' ? 'desc' : 'asc',
                        ]
                    ));
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
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentReceipts as $receipt)
                            <tr class="group hover:bg-gray-50 transition-colors">
                                <td class="px-3 py-3 font-medium whitespace-nowrap">{{ $receipt->nfse_number ?: '—' }}</td>
                                <td class="px-3 py-3">{{ $receipt->customer_name ?: '—' }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    @if ($receipt->data_emissao)
                                        <x-date :date="$receipt->data_emissao" />
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">{{ money($receipt->amount, $receipt->currency_code ?: default_currency(), true) }}</td>
                                <td class="px-3 py-3">{{ trans('nfse::general.native_invoice.status_' . ($receipt->status ?: 'unknown')) }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">
                                    <a class="text-indigo-700 hover:underline" href="{{ route('invoices.show', $receipt->invoice_id) }}"
                                        title="{{ trans('nfse::general.ledger.open_invoice') }}">{{ trans('nfse::general.dashboard.open') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-4 text-gray-500">{{ trans('nfse::general.dashboard.no_recent_receipts') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </x-slot>
</x-layouts.admin>
