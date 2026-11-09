{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
@canany(['read-nfse-settings', 'update-nfse-settings'])
    @php
        $nfseExistingMode = $nfseCategoryIssuancePolicy['mode'] ?? '';
        $nfseFiscalHistory = $nfseCategoryIssuanceHistory ?? [];
    @endphp
    <x-form.section v-if="form.type === 'income'" data-nfse-native-category-policy="true">
        <x-slot name="head">
            <x-form.section.head
                title="{{ trans('nfse::general.category_issuance.native_title') }}"
                description="{{ trans('nfse::general.category_issuance.native_help') }}"
            />
        </x-slot>

        <x-slot name="body">
            @can('update-nfse-settings')
                <x-form.group.select
                    name="nfse_category_issuance_mode"
                    label="{{ trans('nfse::general.category_issuance.mode') }}"
                    :options="collect([
                        '' => trans('nfse::general.category_issuance.unconfigured'),
                        'issue' => trans('nfse::general.category_issuance.issue'),
                        'do_not_issue' => trans('nfse::general.category_issuance.do_not_issue'),
                    ])"
                    :selected="old('nfse_category_issuance_mode', $nfseExistingMode)"
                    form-group-class="sm:col-span-6"
                    not-required
                />
                <x-form.group.textarea
                    name="nfse_category_issuance_justification"
                    label="{{ trans('nfse::general.category_issuance.justification') }}"
                    :value="old('nfse_category_issuance_justification', '')"
                    form-group-class="sm:col-span-6"
                    not-required
                />
                <p class="sm:col-span-6 text-xs text-gray-600">
                    {{ trans('nfse::general.category_issuance.native_justification_hint') }}
                </p>
            @else
                <p class="sm:col-span-6 text-sm text-gray-600" data-nfse-native-category-read-only="true">
                    {{ trans('nfse::general.category_issuance.' . ($nfseExistingMode ?: 'unconfigured')) }}
                </p>
            @endcan
        </x-slot>

        @if($category !== null && count($nfseFiscalHistory) > 0)
            <x-slot name="foot">
                <details class="text-sm" data-nfse-native-category-policy-history>
                    <summary class="cursor-pointer text-indigo-700">
                        {{ trans('nfse::general.category_issuance.native_history') }}
                    </summary>
                    <ol class="mt-2 space-y-2">
                        @foreach($nfseFiscalHistory as $revision)
                            <li class="border-l-2 border-gray-200 pl-3 text-gray-600">
                                <strong>{{ trans('nfse::general.category_issuance.' . $revision['mode']) }}</strong>
                                — {{ $revision['recorded_at'] }}
                                · {{ $revision['justification'] }}
                            </li>
                        @endforeach
                    </ol>
                </details>
            </x-slot>
        @endif
    </x-form.section>
@endcanany
