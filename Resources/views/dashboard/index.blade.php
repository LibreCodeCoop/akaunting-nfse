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

        <div id="nfse-dashboard"
            data-read-url="{{ route('nfse.dashboard.data') }}"
            data-empty-message="{{ trans('nfse::general.dashboard.no_recent_receipts') }}"
            data-error-message="{{ trans('nfse::general.nfse_refresh_failed') }}"
            data-invoice-label="{{ trans('nfse::general.go_to_invoices') }}"
            data-sandbox-label="{{ trans('nfse::general.dashboard.sandbox_on') }}"
            data-production-label="{{ trans('nfse::general.dashboard.sandbox_off') }}">
            <p id="nfse-dashboard-feedback" role="status" aria-live="polite" tabindex="-1" hidden></p>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <div class="bg-white border rounded p-4">
                    <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.total_receipts') }}</p>
                    <p id="nfse-dashboard-total" class="text-2xl font-semibold">—</p>
                </div>
                <div class="bg-white border rounded p-4">
                    <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.emitted') }}</p>
                    <p id="nfse-dashboard-emitted" class="text-2xl font-semibold text-green-700">—</p>
                </div>
                <div class="bg-white border rounded p-4">
                    <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.cancelled') }}</p>
                    <p id="nfse-dashboard-cancelled" class="text-2xl font-semibold text-red-700">—</p>
                </div>
                <div class="bg-white border rounded p-4">
                    <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.dashboard.environment') }}</p>
                    <p id="nfse-dashboard-environment" class="text-lg font-semibold">—</p>
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
                        <tbody id="nfse-dashboard-receipts" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>
        </div>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/dashboard-browser.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/dashboard-browser.js'))) }}" data-nfse-dashboard="true"></script>
    </x-slot>
</x-layouts.admin>
