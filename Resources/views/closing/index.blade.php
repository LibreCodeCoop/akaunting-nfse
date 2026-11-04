{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.closing.title') }}</x-slot>
    <x-slot name="content">
        <section id="nfse-closing" class="space-y-6"
            data-list-url="{{ route('nfse.closing.data') }}"
            data-invoice-url="{{ route('invoices.show', '__INVOICE_ID__') }}">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <form method="GET" action="{{ route('nfse.closing.index') }}" class="flex items-end gap-3">
                    <label for="nfse-closing-competence" class="block text-sm font-medium">
                        {{ trans('nfse::general.closing.competence') }}
                        <input id="nfse-closing-competence" type="month" name="competence"
                            value="{{ $competence }}" class="mt-1 rounded border px-3 py-2">
                    </label>
                    <button type="submit" class="rounded bg-green-600 px-4 py-2 text-white">
                        {{ trans('nfse::general.closing.apply') }}
                    </button>
                </form>
                <a href="{{ route('nfse.closing.export', ['competence' => $competence]) }}"
                   class="rounded border px-4 py-2 font-medium">
                    {{ trans('nfse::general.closing.export_csv') }}
                </a>
            </div>

            <p id="nfse-closing-status" role="status" aria-live="polite" class="text-sm text-gray-600">
                Consultando dados fiscais pela API...
            </p>
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.gross') }}</div>
                    <div id="closing-gross" class="text-xl font-semibold">—</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.iss') }}</div>
                    <div id="closing-iss" class="text-xl font-semibold">—</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.active_documents') }}</div>
                    <div id="closing-emitted" class="text-xl font-semibold">—</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.mismatches') }}</div>
                    <div id="closing-mismatches" class="text-xl font-semibold">—</div>
                </div>
            </div>

            <section id="nfse-closing-mismatch" class="rounded border border-yellow-300 bg-yellow-50 p-4" hidden
                aria-labelledby="nfse-closing-mismatch-title">
                <h2 id="nfse-closing-mismatch-title" class="font-semibold">
                    {{ trans('nfse::general.closing.reconciliation_attention') }}
                </h2>
                <ul id="closing-reconciliation" class="mt-2 space-y-1 text-sm"></ul>
            </section>

            <section class="rounded border p-4 space-y-3" aria-labelledby="commercial-review-title">
                <h2 id="commercial-review-title" class="font-semibold">Revisão de faturas comerciais do mês</h2>
                <p class="text-sm text-gray-600">
                    Esta lista usa a data comercial da fatura, não a data do fato gerador.
                    Ausência de NFS-e local ou emissão parcial é uma pendência de verificação,
                    nunca prova de atraso ou infração fiscal. Documentos externos exigem conciliação.
                    Estes valores não entram nos totais fiscais autorizados.
                </p>
                <p id="closing-commercial-count" class="text-sm text-gray-600">Consultando faturas do mês...</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left px-3 py-2">Fatura</th>
                                <th class="text-left px-3 py-2">Data financeira</th>
                                <th class="text-left px-3 py-2">Status financeiro</th>
                                <th class="text-left px-3 py-2">Recibos locais</th>
                                <th class="text-left px-3 py-2">Estado de conciliação</th>
                            </tr>
                        </thead>
                        <tbody id="closing-commercial-body">
                            <tr><td colspan="5" class="px-3 py-3 text-sm">Carregando dados da API...</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav class="flex items-center gap-3" aria-label="Páginas de faturas comerciais">
                    <button type="button" id="commercial-prev" class="rounded border px-3 py-2" disabled>Anterior</button>
                    <span id="commercial-page" class="text-sm" aria-live="polite"></span>
                    <button type="button" id="commercial-next" class="rounded border px-3 py-2" disabled>Próxima</button>
                </nav>
            </section>

            <h2 class="font-semibold">Documentos fiscais registrados (XML autorizado)</h2>
            <div class="overflow-x-auto rounded border">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.invoice') }}</th>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.nfse') }}</th>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.status') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans('nfse::general.closing.gross') }}</th>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.taxation') }}</th>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.retention') }}</th>
                            <th class="px-3 py-2 text-left">{{ trans('nfse::general.closing.reconciliation') }}</th>
                        </tr>
                    </thead>
                    <tbody id="closing-fiscal-body">
                        <tr><td colspan="7" class="px-3 py-3 text-sm">Carregando documentos fiscais da API...</td></tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex items-center gap-3" aria-label="Páginas de documentos fiscais">
                <button type="button" id="closing-prev" class="rounded border px-3 py-2" disabled>Anterior</button>
                <span id="closing-page" class="text-sm" aria-live="polite"></span>
                <button type="button" id="closing-next" class="rounded border px-3 py-2" disabled>Próxima</button>
            </nav>
        </section>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/fiscal-closing-browser.js?v=' . module_version('nfse')) }}"
            data-nfse-closing-browser="true"></script>
    </x-slot>
</x-layouts.admin>
