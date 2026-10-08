{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.dashboard.title') }}</x-slot>

    <x-slot name="content">
        <div class="mb-4 flex flex-wrap gap-2">
            <a href="{{ route('nfse.bulk.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.bulk.title') }}
            </a>
            <a href="{{ route('nfse.settings.edit') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_settings') }}
            </a>
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

        <section class="bg-white border rounded p-4">
            <h2 class="text-lg font-semibold mb-3">{{ trans('nfse::general.dashboard.recent_receipts') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr>
                            <th class="text-left p-3">{{ trans('nfse::general.invoices.nfse_number') }}</th>
                            <th class="text-left p-3">{{ trans('nfse::general.invoices.customer') }}</th>
                            <th class="text-left p-3">{{ trans('nfse::general.invoices.issue_date') }}</th>
                            <th class="text-left p-3">{{ trans_choice('general.statuses', 1) }}</th>
                            <th class="text-right p-3">{{ trans('general.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentReceipts as $receipt)
                            <tr>
                                <td class="p-3">{{ $receipt->nfse_number ?: '—' }}</td>
                                <td class="p-3">{{ $receipt->customer_name ?: '—' }}</td>
                                <td class="p-3">{{ $receipt->data_emissao ?: '—' }}</td>
                                <td class="p-3">{{ trans('nfse::general.native_invoice.status_' . ($receipt->status ?: 'unknown')) }}</td>
                                <td class="p-3 text-right">
                                    <a class="text-indigo-700 underline" href="{{ route('invoices.show', $receipt->invoice_id) }}">{{ trans('nfse::general.go_to_invoices') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-4 text-gray-500">{{ trans('nfse::general.dashboard.no_recent_receipts') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </x-slot>
</x-layouts.admin>
