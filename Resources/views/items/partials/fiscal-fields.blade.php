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
        @php($profileServiceCode = (string) ($nfseItemFiscalProfile->item_lista_servico ?? ''))
        @php($profileNationalCode = (string) ($nfseItemFiscalProfile->codigo_tributacao_nacional ?? ''))
        @php($oldServiceCode = old('nfse_item_lista_servico', $profileServiceCode))
        @php($serviceDigits = preg_replace('/\D+/', '', (string) $oldServiceCode) ?: '')
        @php($normalizedServiceCode = preg_match('/(\d{4})$/', $serviceDigits, $serviceCodeMatch) ? (string) $serviceCodeMatch[1] : substr($serviceDigits, 0, 4))
        @php($selectedServiceOption = $normalizedServiceCode !== '' ? 'lc:' . $normalizedServiceCode : '')
        @php($oldNationalCode = old('nfse_codigo_tributacao_nacional', $profileNationalCode))
        @php($lc116Options = [])
        @foreach(($nfseLc116Catalog ?? []) as $entry)
            @php($entryCode = preg_replace('/\D+/', '', (string) ($entry['code'] ?? '')) ?: '')
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
