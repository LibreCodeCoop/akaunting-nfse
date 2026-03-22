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
            </div>
        @endif

        @php
            $receiptsCollection = collect(method_exists($receipts, 'items') ? $receipts->items() : $receipts);
            $visibleTotal = $receiptsCollection->count();
            $visibleEmitted = $receiptsCollection->where('status', 'emitted')->count();
            $visibleProcessing = $receiptsCollection->where('status', 'processing')->count();
            $visibleCancelled = $receiptsCollection->where('status', 'cancelled')->count();
        @endphp

        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">{{ trans('nfse::general.invoices.listing_overview') }}</h3>
                <span class="text-xs text-gray-500">{{ trans('nfse::general.invoices.per_page') }}: {{ $perPage ?? 25 }}</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
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
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-4">
            <form action="{{ route('nfse.invoices.refresh-all') }}" method="POST">
                @csrf
                <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-sm">
                    {{ trans('nfse::general.invoices.refresh_all_statuses') }}
                </button>
            </form>
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

        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <p class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">{{ trans('nfse::general.invoices.quick_filters') }}</p>
            <div class="flex flex-wrap gap-2">
            <a href="{{ route('nfse.invoices.index', ['status' => 'all', 'per_page' => ($perPage ?? 25), 'q' => ($search ?? '')]) }}" class="inline-flex items-center px-3 py-2 rounded text-sm {{ ($status ?? 'all') === 'all' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 hover:bg-gray-200' }}">
                {{ trans('nfse::general.invoices.filter_all') }}
            </a>
            <a href="{{ route('nfse.invoices.index', ['status' => 'emitted', 'per_page' => ($perPage ?? 25), 'q' => ($search ?? '')]) }}" class="inline-flex items-center px-3 py-2 rounded text-sm {{ ($status ?? 'all') === 'emitted' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 hover:bg-gray-200' }}">
                {{ trans('nfse::general.invoices.filter_emitted') }}
            </a>
            <a href="{{ route('nfse.invoices.index', ['status' => 'processing', 'per_page' => ($perPage ?? 25), 'q' => ($search ?? '')]) }}" class="inline-flex items-center px-3 py-2 rounded text-sm {{ ($status ?? 'all') === 'processing' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 hover:bg-gray-200' }}">
                {{ trans('nfse::general.invoices.filter_processing') }}
            </a>
            <a href="{{ route('nfse.invoices.index', ['status' => 'cancelled', 'per_page' => ($perPage ?? 25), 'q' => ($search ?? '')]) }}" class="inline-flex items-center px-3 py-2 rounded text-sm {{ ($status ?? 'all') === 'cancelled' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 hover:bg-gray-200' }}">
                {{ trans('nfse::general.invoices.filter_cancelled') }}
            </a>
            <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded text-sm bg-gray-100 hover:bg-gray-200">
                {{ trans('nfse::general.invoices.clear_filters') }}
            </a>
            </div>
        </div>

        <form method="GET" action="{{ route('nfse.invoices.index') }}" class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <input type="hidden" name="status" value="{{ $status ?? 'all' }}">

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div class="md:col-span-2">
                    <label for="q" class="block text-xs font-semibold text-gray-600 uppercase mb-1">{{ trans('nfse::general.invoices.search_label') }}</label>
                    <input id="q" name="q" type="text" value="{{ $search ?? '' }}" list="nfse-search-suggestions" placeholder="{{ trans('nfse::general.invoices.search_placeholder') }}" class="w-full px-3 py-2 rounded border border-gray-300 text-sm">
                    <datalist id="nfse-search-suggestions">
                        <option value="NF-2026-0001"></option>
                        <option value="CHAVE-"></option>
                        <option value="ABC123"></option>
                    </datalist>
                </div>

                <div>
                    <label for="per_page" class="block text-xs font-semibold text-gray-600 uppercase mb-1">{{ trans('nfse::general.invoices.per_page') }}</label>
                    <select id="per_page" name="per_page" class="w-full px-3 py-2 rounded border border-gray-300 text-sm">
                        @foreach([10, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @if(($perPage ?? 25) === $option) selected @endif>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
                        {{ trans('general.apply') }}
                    </button>
                    <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                        {{ trans('nfse::general.invoices.clear_filters') }}
                    </a>
                </div>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.invoice') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">NFS-e</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.date') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('nfse::general.invoices.more_details') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($receipts as $receipt)
                        <tr>
                            <td class="px-6 py-4">{{ $receipt->invoice?->number ?? '—' }}</td>
                            <td class="px-6 py-4">{{ $receipt->nfse_number ?? '—' }}</td>
                            <td class="px-6 py-4">{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</td>
                            <td class="px-6 py-4">
                                @php
                                    $statusClass = match ($receipt->status ?? '') {
                                        'emitted' => 'bg-green-100 text-green-700',
                                        'cancelled' => 'bg-red-100 text-red-700',
                                        'processing' => 'bg-blue-100 text-blue-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }}">{{ $receipt->status }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <details class="group">
                                    <summary class="cursor-pointer text-sm text-indigo-700 hover:underline">{{ trans('nfse::general.invoices.more_details') }}</summary>
                                    <dl class="mt-2 grid grid-cols-1 gap-1 text-xs text-gray-600">
                                        <div class="flex gap-2"><dt class="font-semibold">{{ trans('nfse::general.invoices.access_key') }}:</dt><dd class="break-all">{{ $receipt->chave_acesso ?? '—' }}</dd></div>
                                        <div class="flex gap-2"><dt class="font-semibold">{{ trans('nfse::general.invoices.verification_code') }}:</dt><dd>{{ $receipt->codigo_verificacao ?? '—' }}</dd></div>
                                        <div class="flex gap-2"><dt class="font-semibold">{{ trans('general.customer') }}:</dt><dd>{{ $receipt->invoice?->contact?->name ?? '—' }}</dd></div>
                                    </dl>
                                </details>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center gap-3">
                                    <form action="{{ route('nfse.invoices.refresh', $receipt->invoice_id) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="text-gray-600 hover:underline">{{ trans('nfse::general.invoices.refresh_status') }}</button>
                                    </form>
                                    @if(($receipt->status ?? '') === 'emitted')
                                        <form action="{{ route('nfse.invoices.cancel', $receipt->invoice_id) }}" method="POST" onsubmit="return confirm('{{ trans('nfse::general.invoices.cancel_confirm') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-700 hover:underline">{{ trans('nfse::general.invoices.cancel') }}</button>
                                        </form>
                                    @endif
                                    @if(($receipt->status ?? '') === 'cancelled')
                                        <form action="{{ route('nfse.invoices.reemit', $receipt->invoice_id) }}" method="POST" onsubmit="return confirm('{{ trans('nfse::general.invoices.reemit_confirm') }}')">
                                            @csrf
                                            <button type="submit" class="text-green-700 hover:underline">{{ trans('nfse::general.invoices.reemit') }}</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}" class="text-indigo-600 hover:underline">{{ trans('general.view') }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">{{ trans('general.no_records') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $receipts->appends(request()->query())->links() }}
        </div>
    </x-slot>
</x-layouts.admin>
