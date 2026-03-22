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

        <div class="flex flex-wrap gap-2 mb-4">
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

        <form method="GET" action="{{ route('nfse.invoices.index') }}" class="mb-4 flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $status ?? 'all' }}">
            <input type="hidden" name="per_page" value="{{ $perPage ?? 25 }}">
            <label for="q" class="text-sm text-gray-700">{{ trans('nfse::general.invoices.search_label') }}</label>
            <input id="q" name="q" type="text" value="{{ $search ?? '' }}" placeholder="{{ trans('nfse::general.invoices.search_placeholder') }}" class="px-3 py-2 rounded border border-gray-300 text-sm">
            <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('general.apply') }}
            </button>
        </form>

        <form method="GET" action="{{ route('nfse.invoices.index') }}" class="mb-4 flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $status ?? 'all' }}">
            <input type="hidden" name="q" value="{{ $search ?? '' }}">
            <label for="per_page" class="text-sm text-gray-700">{{ trans('nfse::general.invoices.per_page') }}</label>
            <select id="per_page" name="per_page" class="px-3 py-2 rounded border border-gray-300 text-sm">
                @foreach([10, 25, 50, 100] as $option)
                    <option value="{{ $option }}" @if(($perPage ?? 25) === $option) selected @endif>{{ $option }}</option>
                @endforeach
            </select>
            <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('general.apply') }}
            </button>
        </form>

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
                                <div class="inline-flex items-center gap-3">
                                    <form action="{{ route('nfse.invoices.refresh', $receipt->invoice_id) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="text-gray-600 hover:underline">{{ trans('nfse::general.invoices.refresh_status') }}</button>
                                    </form>
                                    <a href="{{ route('nfse.invoices.show', $receipt->invoice_id) }}" class="text-indigo-600 hover:underline">{{ trans('general.view') }}</a>
                                </div>
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
            {{ $receipts->appends(request()->query())->links() }}
        </div>
    </x-slot>
</x-layouts.admin>
