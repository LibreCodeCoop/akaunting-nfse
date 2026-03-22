{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.dashboard.title') }}</x-slot>

    <x-slot name="content">
        <div class="mb-4 flex flex-wrap gap-2">
            <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
                {{ trans('nfse::general.go_to_invoices') }}
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

        <div class="bg-white border rounded p-4">
            <h2 class="font-semibold mb-3">{{ trans('nfse::general.dashboard.recent_receipts') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.invoice') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NFS-e</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.date') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.status') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($recentReceipts as $receipt)
                            <tr>
                                <td class="px-4 py-2">{{ $receipt->invoice?->number ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $receipt->nfse_number ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</td>
                                <td class="px-4 py-2">{{ $receipt->status }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a class="text-indigo-600 hover:underline" href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}">{{ trans('general.view') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-center text-gray-500">{{ trans('nfse::general.dashboard.no_recent_receipts') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-slot>
</x-layouts.admin>