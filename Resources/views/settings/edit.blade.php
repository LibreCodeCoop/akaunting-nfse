{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.title') }}</x-slot>

    <x-slot name="content">
        <div class="flex flex-col lg:flex-row gap-x-10 gap-y-12">
            <div class="w-full lg:w-2/3">

                {{-- Success / Error flashes --}}
                @if(session('success'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        {{ session('success') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('nfse.settings.update') }}">
                    @csrf
                    @method('PATCH')

                    <x-form.section>
                        <x-slot name="title">{{ trans('nfse::general.settings.title') }}</x-slot>

                        <x-form.group.text
                            name="nfse[cnpj_prestador]"
                            label="{{ trans('nfse::general.settings.cnpj_prestador') }}"
                            value="{{ old('nfse.cnpj_prestador', setting('nfse.cnpj_prestador')) }}"
                            required
                        />

                        <x-form.group.text
                            name="nfse[municipio_ibge]"
                            label="{{ trans('nfse::general.settings.municipio_ibge') }}"
                            value="{{ old('nfse.municipio_ibge', setting('nfse.municipio_ibge', '3303302')) }}"
                            required
                        />

                        <x-form.group.text
                            name="nfse[item_lista_servico]"
                            label="{{ trans('nfse::general.settings.item_lista') }}"
                            value="{{ old('nfse.item_lista_servico', setting('nfse.item_lista_servico', '0107')) }}"
                        />

                        <x-form.group.text
                            name="nfse[aliquota]"
                            label="{{ trans('nfse::general.settings.aliquota') }}"
                            value="{{ old('nfse.aliquota', setting('nfse.aliquota', '5.00')) }}"
                        />

                        <x-form.group.checkbox
                            name="nfse[sandbox_mode]"
                            label="{{ trans('nfse::general.settings.sandbox_mode') }}"
                            :checked="(bool) setting('nfse.sandbox_mode', true)"
                        />
                    </x-form.section>

                    <x-form.section>
                        <x-slot name="title">OpenBao / Vault</x-slot>

                        <x-form.group.text
                            name="nfse[bao_addr]"
                            label="{{ trans('nfse::general.settings.bao_addr') }}"
                            value="{{ old('nfse.bao_addr', setting('nfse.bao_addr', 'http://openbao:8200')) }}"
                            required
                        />

                        <x-form.group.text
                            name="nfse[bao_mount]"
                            label="{{ trans('nfse::general.settings.bao_mount') }}"
                            value="{{ old('nfse.bao_mount', setting('nfse.bao_mount', 'nfse')) }}"
                        />

                        <x-form.group.text
                            name="nfse[bao_role_id]"
                            label="{{ trans('nfse::general.settings.bao_role_id') }}"
                            value="{{ old('nfse.bao_role_id', setting('nfse.bao_role_id')) }}"
                        />

                        <x-form.group.text
                            type="password"
                            name="nfse[bao_secret_id]"
                            label="{{ trans('nfse::general.settings.bao_secret_id') }}"
                            value=""
                            autocomplete="new-password"
                        />
                    </x-form.section>

                    <div class="mt-6">
                        <x-button type="submit">{{ trans('general.save') }}</x-button>
                    </div>
                </form>

                {{-- Certificate upload --}}
                <div class="mt-10">
                    <x-form.section>
                        <x-slot name="title">{{ trans('nfse::general.settings.certificate') }}</x-slot>

                        <form method="POST" action="{{ route('nfse.certificate.upload') }}" enctype="multipart/form-data">
                            @csrf

                            <x-form.group.file
                                name="pfx_file"
                                label="{{ trans('nfse::general.settings.certificate') }}"
                                accept=".pfx,.p12"
                                required
                            />

                            <x-form.group.text
                                type="password"
                                name="pfx_password"
                                label="{{ trans('nfse::general.settings.pfx_password') }}"
                                autocomplete="new-password"
                                required
                            />

                            <div class="mt-4 flex gap-4">
                                <x-button type="submit">{{ trans('general.upload') }}</x-button>

                                <form method="POST" action="{{ route('nfse.certificate.destroy') }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button type="submit" kind="danger">{{ trans('general.delete') }}</x-button>
                                </form>
                            </div>
                        </form>
                    </x-form.section>
                </div>

            </div>
        </div>
    </x-slot>
</x-layouts.admin>
