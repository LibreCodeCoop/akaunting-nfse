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
            <a href="{{ route('nfse.invoices.index', ['status' => 'pending']) }}" class="inline-flex items-center px-3 py-2 rounded bg-indigo-100 hover:bg-indigo-200 text-indigo-700 text-sm">
                {{ trans('nfse::general.go_to_pending_invoices') }}
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
    </x-slot>
</x-layouts.admin>
