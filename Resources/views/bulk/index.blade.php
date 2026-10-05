{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.bulk.title') }}</x-slot>

    <x-slot name="content">
        <div class="space-y-6" id="nfse-bulk-progress">
            <div class="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                {{ trans('nfse::general.bulk.operational_notice') }}
            </div>

            @forelse($runs as $run)
                @php
                    $runId = (int) $run->id;
                    $units = $unitsByRun[$runId] ?? collect();
                @endphp
                <section class="rounded border bg-white" aria-labelledby="nfse-bulk-run-{{ $runId }}">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <div>
                            <h2 id="nfse-bulk-run-{{ $runId }}" class="font-semibold">
                                {{ trans('nfse::general.bulk.run') }} #{{ $runId }}
                            </h2>
                            <p class="text-xs text-gray-500">
                                {{ trans('nfse::general.bulk.selection') }}:
                                <span class="font-mono">{{ $run->selection_hash }}</span>
                            </p>
                        </div>
                        <span class="rounded bg-gray-100 px-2 py-1 text-xs font-medium" data-bulk-run-status="{{ $run->status }}">
                            {{ $run->status }}
                        </span>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ trans('nfse::general.bulk.invoice') }}</th>
                                    <th class="px-3 py-2 text-left">{{ trans('nfse::general.bulk.group') }}</th>
                                    <th class="px-3 py-2 text-left">{{ trans('nfse::general.bulk.status') }}</th>
                                    <th class="px-3 py-2 text-left">{{ trans('nfse::general.bulk.receipt') }}</th>
                                    <th class="px-3 py-2 text-left">{{ trans('nfse::general.bulk.error') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($units as $unit)
                                    <tr class="border-t" data-bulk-unit-status="{{ $unit->status }}">
                                        <td class="px-3 py-2">
                                            <a class="text-indigo-700 underline" href="{{ route('invoices.show', $unit->invoice_id) }}">
                                                #{{ $unit->invoice_id }}
                                            </a>
                                        </td>
                                        <td class="max-w-md break-all px-3 py-2 font-mono text-xs">{{ $unit->emission_group_key }}</td>
                                        <td class="px-3 py-2">{{ $unit->status }}</td>
                                        <td class="px-3 py-2">{{ $unit->receipt_id ?: '—' }}</td>
                                        <td class="px-3 py-2">
                                            @if($unit->error_type || $unit->error_message)
                                                <span class="font-medium">{{ $unit->error_type ?: trans('nfse::general.bulk.error') }}</span>
                                                @if($unit->error_message)
                                                    <div class="mt-1 max-w-xl break-words text-xs text-gray-600">{{ $unit->error_message }}</div>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @empty
                <div class="rounded border bg-white p-6 text-center text-gray-500">
                    {{ trans('nfse::general.bulk.empty') }}
                </div>
            @endforelse
        </div>
    </x-slot>
</x-layouts.admin>
