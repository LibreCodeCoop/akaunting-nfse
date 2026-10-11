{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Native address component extended by NFS-e, without editing Akaunting. --}}
<x-form.section>
    <x-slot name="head">
        <x-form.section.head
            title="{{ trans($textSectionAddressTitle) }}"
            description="{{ trans($textSectionAddressDescription) }}"
        />
    </x-slot>
    <x-slot name="body">
        @if (! $hideAddress)
            <x-form.group.textarea name="address" label="{{ trans($textAddress) }}" not-required v-model="form.address" />
        @endif
        @if (! $hideCity)
            <x-form.group.text name="city" label="{{ trans_choice($textCity, 1) }}" not-required />
        @endif
        @if (! $hideZipCode)
            <x-form.group.text name="zip_code" label="{{ trans($textZipCode) }}" not-required />
        @endif
        @if (! $hideState)
            <x-form.group.text name="state" label="{{ trans($textState) }}" not-required />
        @endif
        @if (! $hideCountry)
            <x-form.group.country form-group-class="sm:col-span-3 el-select-tags-pl-38" not-required />
        @endif

        @if ($type === 'customer')
            <x-form.group.text
                name="nfse_municipal_registration" maxlength="15"
                label="{{ trans('nfse::general.contacts.municipal_registration') }}"
                :value="$nfseMunicipalRegistration ?? ''" not-required
            />
            <x-form.group.text
                name="nfse_legal_name"
                label="{{ trans('nfse::general.contacts.legal_name') }}"
                :value="$nfseLegalName ?? ''" not-required
            />
        @endif
    </x-slot>
</x-form.section>
