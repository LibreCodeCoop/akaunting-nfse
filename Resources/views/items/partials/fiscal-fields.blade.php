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

            @if(isset($fiscalValidation['municipal_status']))
                <p class="mt-2 text-xs font-medium" data-nfse-municipal-validation="{{ $fiscalValidation['municipal_status'] }}">
                    {{ trans('nfse::general.items.validation.municipal_status_' . $fiscalValidation['municipal_status']) }}
                </p>
                @if(($fiscalValidation['municipal_issues'] ?? []) !== [])
                    <ul class="mt-1 list-disc pl-5 text-xs">
                        @foreach($fiscalValidation['municipal_issues'] as $issue)
                            <li>{{ trans('nfse::general.items.validation.' . $issue, [
                                'rate' => $fiscalValidation['municipal_official_rate'] ?? '',
                            ]) }}</li>
                        @endforeach
                    </ul>
                @endif
                @if(($fiscalValidation['municipal_source']['fetched_at'] ?? '') !== '')
                    <p class="mt-1 text-xs" data-nfse-municipal-fetched-at="{{ $fiscalValidation['municipal_source']['fetched_at'] }}">
                        {{ trans('nfse::general.items.validation.municipal_source', [
                            'fetched_at' => $fiscalValidation['municipal_source']['fetched_at'],
                            'environment' => $fiscalValidation['municipal_source']['environment'] ?? '',
                        ]) }}
                    </p>
                @endif
            @endif
        </div>
        @php($profileServiceCode = (string) ($nfseItemFiscalProfile->item_lista_servico ?? ''))
        @php($profileNationalCode = (string) ($nfseItemFiscalProfile->codigo_tributacao_nacional ?? ''))
        @php($profileMunicipalCode = (string) ($nfseItemFiscalProfile->codigo_tributacao_municipal ?? ''))
        @php($oldServiceCode = old('nfse_item_lista_servico', $profileServiceCode))
        @php($normalizedServiceCode = \Modules\Nfse\Support\Lc116Code::normalize($oldServiceCode))
        @php($selectedServiceOption = $normalizedServiceCode !== '' ? 'lc:' . $normalizedServiceCode : '')
        @php($oldNationalCode = old('nfse_codigo_tributacao_nacional', $profileNationalCode))
        @php($oldMunicipalCode = old('nfse_codigo_tributacao_municipal', $profileMunicipalCode))
        @php($nationalServiceOptions = [])
        @foreach(($nfseNationalServiceCatalog ?? []) as $entry)
            @php($entryCode = preg_replace('/\D+/', '', (string) ($entry['code'] ?? '')) ?? '')
            @if(strlen($entryCode) === 6)
                @php($nationalServiceOptions[$entryCode] = $entryCode . ' - ' . (string) ($entry['description'] ?? ''))
            @endif
        @endforeach
        @if($oldNationalCode !== '' && !array_key_exists((string) $oldNationalCode, $nationalServiceOptions))
            @php($nationalServiceOptions[(string) $oldNationalCode] = (string) $oldNationalCode . ' - ' . trans('nfse::general.items.codigo_tributacao_nacional_legacy'))
        @endif
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

        <x-form.group.select
            name="nfse_codigo_tributacao_nacional"
            label="{{ trans('nfse::general.items.codigo_tributacao_nacional') }}"
            :options="$nationalServiceOptions"
            :selected="$oldNationalCode"
            placeholder="{{ trans('nfse::general.items.codigo_tributacao_nacional_placeholder') }}"
            searchable
            sort-options="false"
            form-group-class="sm:col-span-6"
            not-required
        />

        <div class="sm:col-span-6 -mt-3">
            <p class="text-xs text-gray-500">{{ trans('nfse::general.items.codigo_tributacao_nacional_hint') }}</p>
        </div>

        {{-- Optional catalog navigation. The saved fiscal fields remain Akaunting's native selects. --}}
        <div class="sm:col-span-6 rounded border p-3" data-nfse-tax-code-assistant
             data-lc-options='@json($lc116Options)'
             data-national-options='@json($nationalServiceOptions)'
             data-matches-label="{{ trans('nfse::general.items.assistant.matches') }}">
            <label for="nfse-tax-code-search" class="block text-sm font-medium">{{ trans('nfse::general.items.assistant.search_label') }}</label>
            <input id="nfse-tax-code-search" type="search" autocomplete="off"
                   class="mt-1 w-full rounded border px-3 py-2"
                   placeholder="{{ trans('nfse::general.items.assistant.search_placeholder') }}"
                   data-nfse-tax-code-query>
            <p class="mt-2 text-xs text-gray-600">{{ trans('nfse::general.items.assistant.advisory') }}</p>
            <p class="mt-2 text-sm" data-nfse-tax-code-summary role="status" aria-live="polite"></p>
            <div class="mt-2 max-h-56 overflow-y-auto" data-nfse-tax-code-results role="group"
                 aria-label="{{ trans('nfse::general.items.assistant.results') }}"></div>
        </div>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/tax-code-assistant.js') }}" defer></script>

        <x-form.group.text
            name="nfse_codigo_tributacao_municipal"
            label="{{ trans('nfse::general.items.codigo_tributacao_municipal') }}"
            :value="$oldMunicipalCode"
            placeholder="{{ trans('nfse::general.items.codigo_tributacao_municipal_placeholder') }}"
            maxlength="3"
            form-group-class="sm:col-span-6"
            not-required
        />

        <div class="sm:col-span-6 -mt-3">
            <p class="text-xs text-gray-500">{{ trans('nfse::general.items.codigo_tributacao_municipal_hint') }}</p>
        </div>
    </x-slot>
</x-form.section>
