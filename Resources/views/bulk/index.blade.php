{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.bulk.title') }}</x-slot>

    <x-slot name="content">
        <section id="nfse-bulk-progress" class="space-y-4"
            data-runs-url="{{ route('nfse.bulk.runs') }}"
            data-invoice-url="{{ route('invoices.show', '__INVOICE_ID__') }}">
            <p class="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                {{ trans('nfse::general.bulk.operational_notice') }}
            </p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p id="nfse-bulk-status" role="status" aria-live="polite" class="text-sm text-gray-600">
                    Consultando andamento dos lotes...
                </p>
                <button type="button" id="nfse-bulk-refresh" class="rounded border px-3 py-2">
                    Atualizar andamento
                </button>
            </div>
            <div id="nfse-bulk-runs" class="space-y-4"></div>
            <nav class="flex flex-wrap items-center gap-3" aria-label="Páginas de lotes fiscais">
                <button type="button" id="nfse-bulk-prev" class="rounded border px-3 py-2" disabled>Anterior</button>
                <span id="nfse-bulk-page" aria-live="polite" class="text-sm"></span>
                <button type="button" id="nfse-bulk-next" class="rounded border px-3 py-2" disabled>Próxima</button>
            </nav>
        </section>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/bulk-progress-browser.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/bulk-progress-browser.js'))) }}"
            data-nfse-bulk-progress-browser="true"></script>
    </x-slot>
</x-layouts.admin>
