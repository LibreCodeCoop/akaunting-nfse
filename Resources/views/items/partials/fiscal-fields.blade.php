{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-form.section>
    <x-slot name="head">
        <x-form.section.head
            title="{{ trans('nfse::general.items.fiscal_title') }}"
            description="{{ trans('nfse::general.items.fiscal_description') }}"
        />
    </x-slot>

    <x-slot name="body">
        @php($fiscalValidation = $nfseItemFiscalValidation ?? ['status' => 'unverifiable', 'issues' => [], 'source_versions' => []])
        @php($fiscalStatus = (string) ($fiscalValidation['status'] ?? 'unverifiable'))
        @php($statusClasses = [
            'valid' => 'border-green-300 bg-green-50 text-green-800',
            'warning' => 'border-yellow-300 bg-yellow-50 text-yellow-800',
            'invalid' => 'border-red-300 bg-red-50 text-red-800',
            'unverifiable' => 'border-gray-300 bg-gray-50 text-gray-700',
        ])

        <div class="sm:col-span-6 rounded border px-3 py-2 text-sm {{ $statusClasses[$fiscalStatus] ?? $statusClasses['unverifiable'] }}" data-nfse-fiscal-profile-status="{{ $fiscalStatus }}">
            <p class="font-medium">
                {{ trans('nfse::general.items.validation.status_' . $fiscalStatus) }}
            </p>

            @if(($fiscalValidation['issues'] ?? []) !== [])
                <ul class="mt-1 list-disc pl-5">
                    @foreach($fiscalValidation['issues'] as $issue)
                        <li>{{ trans('nfse::general.items.validation.' . $issue) }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-1 text-xs">
                {{ trans('nfse::general.items.validation.correlation_unverifiable') }}
            </p>

            @if(isset($fiscalValidation['source_versions']['service_nbs']))
                <p class="mt-1 text-xs">
                    {{ trans('nfse::general.items.validation.source_version', ['version' => $fiscalValidation['source_versions']['service_nbs']]) }}
                </p>
            @endif
        </div>
        @php($profileServiceCode = (string) ($nfseItemFiscalProfile->item_lista_servico ?? ''))
        @php($profileNationalCode = (string) ($nfseItemFiscalProfile->codigo_tributacao_nacional ?? ''))
        @php($oldServiceCode = old('nfse_item_lista_servico', $profileServiceCode))
        @php($normalizedServiceCode = \Modules\Nfse\Support\Lc116Code::normalize($oldServiceCode))
        @php($selectedServiceOption = $normalizedServiceCode !== '' ? 'lc:' . $normalizedServiceCode : '')
        @php($oldNationalCode = old('nfse_codigo_tributacao_nacional', $profileNationalCode))
        @php($lc116Options = [])
        @foreach(($nfseLc116Catalog ?? []) as $entry)
            @php($entryCode = \Modules\Nfse\Support\Lc116Code::normalize($entry['code'] ?? ''))
            @if($entryCode !== '')
                @php($lc116Options['lc:' . $entryCode] = (string) ($entry['display_code'] ?? $entryCode) . ' - ' . (string) ($entry['description'] ?? ''))
            @endif
        @endforeach

        <x-form.group.select
            name="nfse_item_lista_servico"
            label="{{ trans('nfse::general.items.item_lista_servico') }}"
            :options="$lc116Options"
            :selected="$selectedServiceOption"
            placeholder="{{ trans('nfse::general.items.item_lista_servico_placeholder') }}"
            searchable
            sort-options="false"
            form-group-class="sm:col-span-6"
            not-required
        />

        <div class="sm:col-span-6 -mt-3">
            <p class="text-xs text-gray-500">{{ trans('nfse::general.items.item_lista_servico_hint') }}</p>
        </div>

        <x-form.group.text
            name="nfse_codigo_tributacao_nacional"
            label="{{ trans('nfse::general.items.codigo_tributacao_nacional') }}"
            :value="$oldNationalCode"
            placeholder="{{ trans('nfse::general.items.codigo_tributacao_nacional_placeholder') }}"
            maxlength="6"
            form-group-class="sm:col-span-6"
            not-required
        />

        <div class="sm:col-span-6 -mt-3">
            <p class="text-xs text-gray-500">{{ trans('nfse::general.items.codigo_tributacao_nacional_hint') }}</p>
        </div>
    </x-slot>
</x-form.section>
