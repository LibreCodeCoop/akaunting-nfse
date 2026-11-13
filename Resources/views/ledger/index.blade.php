{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.ledger.title') }}</x-slot>
    <x-slot name="content">
        <section id="nfse-ledger-browser"
            data-list-url="{{ route('nfse.ledger.data') }}"
            data-invoice-url="{{ route('invoices.show', '__INVOICE_ID__') }}"
            data-artifact-url="{{ route('nfse.ledger.artifacts.download', ['receipt' => '__RECEIPT_ID__', 'artifact' => '__ARTIFACT__']) }}"
            data-historical-no-invoice="{{ trans('nfse::general.ledger.historical_no_invoice') }}"
            data-status-emitted="{{ trans('nfse::general.ledger.status_emitted') }}"
            data-status-processing="{{ trans('nfse::general.ledger.status_processing') }}"
            data-status-cancelled="{{ trans('nfse::general.ledger.status_cancelled') }}"
            data-status-substituted="{{ trans('nfse::general.ledger.status_substituted') }}">
            <form id="nfse-ledger-filter" method="GET" action="{{ route('nfse.ledger.index') }}" class="mb-4 flex flex-wrap gap-2">
                <input name="search" value="{{ $search }}" class="border rounded px-3 py-2"
                    placeholder="{{ trans('nfse::general.ledger.search') }}" />
                <select name="status" class="border rounded px-3 py-2">
                    @foreach (['all', 'emitted', 'processing', 'cancelled', 'substituted'] as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ trans('nfse::general.ledger.status_' . $option) }}</option>
                    @endforeach
                </select>
                <label>{{ trans('nfse::general.ledger.from') }}
                    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2" />
                </label>
                <label>{{ trans('nfse::general.ledger.to') }}
                    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2" />
                </label>
                <button type="submit" class="bg-indigo-600 text-white rounded px-4 py-2">
                    {{ trans('nfse::general.ledger.filter') }}
                </button>
            </form>
            <p id="nfse-ledger-status" role="status" aria-live="polite" class="text-sm text-gray-600">
                Consultando o livro fiscal pela API...
            </p>
            <div class="bg-white border rounded overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr>
                        <th class="text-left p-3">{{ trans('nfse::general.invoices.nfse_number') }}</th>
                        <th class="text-left p-3">{{ trans('nfse::general.invoices.customer') }}</th>
                        <th class="text-left p-3">{{ trans('nfse::general.invoices.issue_date') }}</th>
                        <th class="text-left p-3">{{ trans_choice('general.statuses', 1) }}</th>
                        <th class="text-right p-3">{{ trans('general.actions') }}</th>
                    </tr></thead>
                    <tbody id="nfse-ledger-rows" class="divide-y divide-gray-100">
                        <tr><td colspan="5" class="p-4">Carregando recibos fiscais...</td></tr>
                    </tbody>
                </table>
            </div>
            <nav class="mt-4 flex items-center gap-3" aria-label="Páginas do livro fiscal">
                <button id="nfse-ledger-prev" type="button" class="rounded border px-3 py-2" disabled>Anterior</button>
                <span id="nfse-ledger-page" class="text-sm" aria-live="polite"></span>
                <button id="nfse-ledger-next" type="button" class="rounded border px-3 py-2" disabled>Próxima</button>
            </nav>
        </section>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/fiscal-ledger-browser.js?v=' . module_version('nfse')) }}"
            data-nfse-ledger-browser="true"></script>
    </x-slot>
</x-layouts.admin>
