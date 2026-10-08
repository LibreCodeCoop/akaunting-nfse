{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.ledger.title') }}</x-slot>
    <x-slot name="content">
        <form method="GET" action="{{ route('nfse.ledger.index') }}" class="mb-4 flex flex-wrap gap-2">
            <input name="search" value="{{ $search }}" class="border rounded px-3 py-2" placeholder="{{ trans('nfse::general.ledger.search') }}" />
            <select name="status" class="border rounded px-3 py-2">
                @foreach (['all', 'emitted', 'processing', 'cancelled', 'substituted'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ trans('nfse::general.ledger.status_' . $option) }}</option>
                @endforeach
            </select>
            <button type="submit" class="bg-indigo-600 text-white rounded px-4 py-2">{{ trans('nfse::general.ledger.filter') }}</button>
        </form>
        <div class="bg-white border rounded overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr>
                    <th class="text-left p-3">{{ trans('nfse::general.invoices.nfse_number') }}</th>
                    <th class="text-left p-3">{{ trans('nfse::general.invoices.customer') }}</th>
                    <th class="text-left p-3">{{ trans('nfse::general.invoices.issue_date') }}</th>
                    <th class="text-left p-3">{{ trans_choice('general.statuses', 1) }}</th>
                    <th class="text-right p-3">{{ trans('general.actions') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                @forelse ($receipts as $receipt)
                    <tr>
                        <td class="p-3">{{ $receipt->nfse_number ?: '—' }}</td>
                        <td class="p-3">{{ $receipt->invoice?->contact?->name ?? '—' }}</td>
                        <td class="p-3">{{ $receipt->data_emissao?->format('d/m/Y') ?? '—' }}</td>
                        <td class="p-3">{{ trans('nfse::general.ledger.status_' . ($receipt->status ?: 'unknown')) }}</td>
                        <td class="p-3 text-right"><a class="text-indigo-700 underline" href="{{ route('invoices.show', $receipt->invoice_id) }}">{{ trans('nfse::general.ledger.open_invoice') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4">{{ trans('nfse::general.ledger.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $receipts->links() }}</div>
    </x-slot>
</x-layouts.admin>
