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

        <div class="flex flex-wrap gap-2 mb-4">
            <a href="{{ route('nfse.dashboard.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_dashboard') }}
            </a>
            <a href="{{ route('nfse.invoices.pending') }}" class="inline-flex items-center px-3 py-2 rounded bg-indigo-100 hover:bg-indigo-200 text-indigo-700 text-sm">
                {{ trans('nfse::general.go_to_pending_invoices') }}
            </a>
            <a href="{{ route('nfse.settings.edit') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_settings') }}
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.invoice') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">NFS-e</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.date') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($receipts as $receipt)
                        <tr>
                            <td class="px-6 py-4">{{ $receipt->invoice?->number ?? '—' }}</td>
                            <td class="px-6 py-4">{{ $receipt->nfse_number ?? '—' }}</td>
                            <td class="px-6 py-4">{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</td>
                            <td class="px-6 py-4">{{ $receipt->status }}</td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}" class="text-indigo-600 hover:underline">{{ trans('general.view') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">{{ trans('general.no_records') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $receipts->links() }}
        </div>
    </x-slot>
</x-layouts.admin>
