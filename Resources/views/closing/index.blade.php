{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.closing.title') }}</x-slot>

    <x-slot name="content">
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <form method="GET" action="{{ route('nfse.closing.index') }}" class="flex items-end gap-3">
                    <div>
                        <label for="nfse-closing-competence" class="block text-sm font-medium">
                            {{ trans('nfse::general.closing.competence') }}
                        </label>
                        <input id="nfse-closing-competence" type="month" name="competence" value="{{ $competence }}" class="mt-1 rounded border px-3 py-2">
                    </div>
                    <button type="submit" class="rounded bg-green-600 px-4 py-2 text-white">
                        {{ trans('nfse::general.closing.apply') }}
                    </button>
                </form>

                <a href="{{ route('nfse.closing.export', ['competence' => $competence]) }}" class="rounded border px-4 py-2 font-medium">
                    {{ trans('nfse::general.closing.export_csv') }}
                </a>
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.gross') }}</div>
                    <div class="text-xl font-semibold">{{ $closing['summary']['gross_service_value'] }}</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.iss') }}</div>
                    <div class="text-xl font-semibold">{{ $closing['summary']['iss']['value'] }}</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.active_documents') }}</div>
                    <div class="text-xl font-semibold">{{ $closing['summary']['documents']['emitted'] }}</div>
                </div>
                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ trans('nfse::general.closing.mismatches') }}</div>
                    <div class="text-xl font-semibold">{{ $closing['summary']['reconciliation_mismatches'] }}</div>
                </div>
            </div>

            @if($closing['reconciliation'] !== [])
                <section class="rounded border border-yellow-300 bg-yellow-50 p-4" aria-labelledby="nfse-closing-mismatch-title">
                    <h2 id="nfse-closing-mismatch-title" class="font-semibold">{{ trans('nfse::general.closing.reconciliation_attention') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach($closing['reconciliation'] as $mismatch)
                            <li>
                                {{ $mismatch['invoice_number'] ?: ('#' . $mismatch['invoice_id']) }}:
                                {{ $mismatch['invoice_amount'] }} / {{ $mismatch['authorized_active_gross'] }}
                                ({{ trans('nfse::general.closing.reason_' . $mismatch['reason']) }})
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="rounded border p-4 space-y-3" aria-labelledby="commercial-review-title">
                <h2 id="commercial-review-title" class="font-semibold">Revisão de faturas comerciais do mês</h2>
                <p class="text-sm text-gray-600">
                    Esta lista usa a data comercial da fatura, não a data do fato gerador.
                    Ausência de NFS-e local ou emissão parcial é uma pendência de verificação,
                    nunca prova de atraso ou infração fiscal. Documentos externos exigem conciliação.
                    Estes valores não entram nos totais fiscais autorizados.
                </p>
                <p class="text-sm text-gray-600">{{ $commercialReview['page']->total() }} fatura(s) no mês comercial, 30 por página.</p>
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
                        <tbody>
                            @forelse($commercialReview['rows'] as $commercial)
                                <tr class="border-t">
                                    <td class="px-3 py-2">
                                        <a class="underline" href="{{ route('invoices.show', $commercial['invoice_id']) }}">
                                            {{ $commercial['invoice_number'] ?: ('#' . $commercial['invoice_id']) }}
                                        </a>
                                    </td>
                                    <td class="px-3 py-2">{{ $commercial['issued_at'] }}</td>
                                    <td class="px-3 py-2">{{ $commercial['financial_status'] }}</td>
                                    <td class="px-3 py-2">{{ $commercial['local_receipts'] }}</td>
                                    <td class="px-3 py-2">
                                        @switch($commercial['status'])
                                            @case('not_linked') Sem NFS-e vinculada no módulo @break
                                            @case('reconciliation_required') Recuperação ou conciliação necessária @break
                                            @case('partially_linked') Grupos fiscais parcialmente vinculados @break
                                            @case('authorized_locally') NFS-e autorizada localmente (não comprova conformidade integral) @break
                                            @default Revisão fiscal necessária
                                        @endswitch
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-4 text-gray-600">Nenhuma fatura neste mês comercial.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $commercialReview['page']->appends(['competence' => $competence])->links() }}
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
                    <tbody>
                        @forelse($closing['rows'] as $row)
                            <tr class="border-t">
                                <td class="px-3 py-2">{{ $row['invoice_number'] ?: ('#' . $row['invoice_id']) }}</td>
                                <td class="px-3 py-2">{{ $row['nfse_number'] }}</td>
                                <td class="px-3 py-2">{{ $row['status'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['gross_service_value'] ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $row['taxation_group'] }}</td>
                                <td class="px-3 py-2">{{ $row['iss_retention'] }}</td>
                                <td class="px-3 py-2">{{ $row['reconciliation_status'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">{{ trans('nfse::general.closing.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-slot>
</x-layouts.admin>
