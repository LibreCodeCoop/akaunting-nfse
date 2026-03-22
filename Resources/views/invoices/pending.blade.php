{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.invoices.pending_title') }}</x-slot>

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
            <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.invoices.back_to_list') }}
            </a>
        </div>

        <div class="bg-white border rounded overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.invoice') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.customer') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.amount') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($pendingInvoices as $invoice)
                        <tr>
                            <td class="px-4 py-2">{{ $invoice->number ?? ('#' . $invoice->id) }}</td>
                            <td class="px-4 py-2">{{ $invoice->contact?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $invoice->amount ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <form action="{{ route('nfse.invoices.emit', $invoice) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
                                        {{ trans('nfse::general.invoices.emit_now') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-center text-gray-500">{{ trans('nfse::general.invoices.no_pending') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-slot>
</x-layouts.admin>