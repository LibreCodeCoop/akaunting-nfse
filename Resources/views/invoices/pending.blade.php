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

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        @if(!$isReady)
            <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 px-4 py-3 rounded mb-4">
                {{ trans('nfse::general.invoices.readiness_incomplete') }}
                <a href="{{ route('nfse.settings.readiness') }}" class="underline">
                    {{ trans('nfse::general.go_to_readiness') }}
                </a>
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

        <div class="flex flex-wrap items-end gap-3 mb-4">
            <form method="GET" action="{{ route('nfse.invoices.pending') }}" class="flex items-end gap-2">
                <div>
                    <label for="pending-q" class="block text-xs font-medium text-gray-600 mb-1">{{ trans('nfse::general.invoices.search_label') }}</label>
                    <input
                        id="pending-q"
                        type="text"
                        name="q"
                        value="{{ $search ?? '' }}"
                        placeholder="{{ trans('nfse::general.invoices.search_placeholder') }}"
                        class="px-3 py-2 border border-gray-300 rounded text-sm"
                    >
                </div>
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
                    {{ trans('nfse::general.invoices.search_label') }}
                </button>
            </form>

            <form method="GET" action="{{ route('nfse.invoices.pending') }}" class="flex items-end gap-2">
                <div>
                    <label for="pending-per-page" class="block text-xs font-medium text-gray-600 mb-1">{{ trans('nfse::general.invoices.per_page') }}</label>
                    <select id="pending-per-page" name="per_page" class="px-3 py-2 border border-gray-300 rounded text-sm" onchange="this.form.submit()">
                        @foreach([10, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                @if(!empty($search))
                    <input type="hidden" name="q" value="{{ $search }}">
                @endif
                <noscript>
                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-gray-600 hover:bg-gray-700 text-white text-sm">
                        OK
                    </button>
                </noscript>
            </form>

            @if(!empty($search) || $perPage !== 25)
                <a href="{{ route('nfse.invoices.pending') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                    {{ trans('nfse::general.invoices.clear_filters') }}
                </a>
            @endif
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
                                    <button
                                        type="submit"
                                        @if(!$isReady) disabled @endif
                                        title="@if(!$isReady){{ trans('nfse::general.invoices.emit_blocked_not_ready') }}@endif"
                                        class="inline-flex items-center px-3 py-2 rounded text-white text-sm @if($isReady)bg-indigo-600 hover:bg-indigo-700 @else bg-gray-400 cursor-not-allowed @endif"
                                    >
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

        <div class="mt-4">
            {{ $pendingInvoices->appends(request()->query())->links() }}
        </div>
    </x-slot>
</x-layouts.admin>
