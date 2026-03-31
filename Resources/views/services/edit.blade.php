{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.services.edit_title') }}</x-slot>

    <x-slot name="content">
        <x-form.container>
            <form method="POST" action="{{ route('nfse.settings.services.update', $service->id) }}">
                @csrf
                @method('PATCH')
                <x-form.section>
                    <x-slot name="head">
                        <x-form.section.head
                            title="{{ trans('nfse::general.settings.services.edit_title') }}"
                            description="{{ trans('nfse::general.settings.services.description') }}"
                        />
                    </x-slot>

                    <x-slot name="body">
                        <div class="relative sm:col-span-6">
                            <label for="item_lista_servico_display" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.lc116_code') }}
                            </label>
                            <input
                                type="text"
                                name="item_lista_servico_display"
                                id="item_lista_servico_display"
                                value="{{ $service->display_name }}"
                                class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100 text-gray-500"
                                disabled
                            >
                        </div>

                        <div class="relative sm:col-span-3">
                            <label for="codigo_tributacao_nacional" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.national_tax_code') }}
                            </label>
                            <input
                                type="text"
                                name="codigo_tributacao_nacional"
                                id="codigo_tributacao_nacional"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                placeholder="Opcional. Ex: 101011"
                                value="{{ old('codigo_tributacao_nacional', $service->codigo_tributacao_nacional) }}"
                                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                            <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.settings.codigo_tributacao_nacional_hint') }}</p>
                            <p class="mt-1 text-xs">
                                <a href="https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs" target="_blank" rel="noopener noreferrer" class="text-blue-700 hover:text-blue-800 underline">
                                    Lista oficial de codigos de tributacao nacional (NBS)
                                </a>
                            </p>
                        </div>

                        <div class="relative sm:col-span-3 required">
                            <label for="aliquota" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.aliquota') }}
                                <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <div class="relative">
                                <input
                                    type="number"
                                    name="aliquota"
                                    id="aliquota"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    value="{{ old('aliquota', $service->aliquota) }}"
                                    required
                                    class="w-full border rounded-lg px-3 py-2 pr-12 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                            </div>
                        </div>

                        <div class="relative sm:col-span-6">
                            <label for="description" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.service_description') }}
                            </label>
                            <textarea
                                name="description"
                                id="description"
                                rows="3"
                                placeholder="Descricao adicional desse servico (opcional)"
                                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            >{{ old('description', $service->description) }}</textarea>
                        </div>
                    </x-slot>
                </x-form.section>

                <x-form.section>
                    <x-slot name="foot">
                        <div class="flex items-center justify-end sm:col-span-6">
                            <a href="{{ route('nfse.settings.edit', ['tab' => 'services']) }}" class="px-6 py-1.5 hover:bg-gray-200 rounded-lg ltr:mr-2 rtl:ml-2">
                                {{ trans('general.cancel') }}
                            </a>

                            <button type="submit" class="relative flex items-center justify-center bg-green hover:bg-green-700 text-white px-6 py-1.5 text-base rounded-lg disabled:bg-green-100">
                                {{ trans('general.save') }}
                            </button>
                        </div>
                    </x-slot>
                </x-form.section>
            </form>

            {{-- Quick Actions --}}
            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-6 py-4">
                <h4 class="mb-3 text-sm font-semibold text-gray-700">{{ trans('general.actions') }}</h4>
                <div class="flex flex-wrap gap-3">

                    {{-- Make Default --}}
                    @if($service->is_default)
                        <span class="inline-flex cursor-default items-center gap-1.5 rounded-lg border border-gray-300 bg-gray-100 px-3 py-1.5 text-sm text-gray-500">
                            <span class="material-icons-outlined text-base text-yellow-500">star</span>
                            {{ trans('nfse::general.settings.services.is_default') }}
                        </span>
                    @elseif($service->is_active)
                        <form method="POST" action="{{ route('nfse.settings.services.make-default', $service->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-gray-50">
                                <span class="material-icons-outlined text-base text-gray-400">star_border</span>
                                {{ trans('nfse::general.settings.services.make_default') }}
                            </button>
                        </form>
                    @else
                        <span class="inline-flex cursor-default items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-400 opacity-50">
                            <span class="material-icons-outlined text-base">star_border</span>
                            {{ trans('nfse::general.settings.services.make_default') }}
                        </span>
                    @endif

                    {{-- Toggle Active --}}
                    <form method="POST" action="{{ route('nfse.settings.services.toggle-active', $service->id) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium {{ $service->is_active ? 'bg-green-50 text-green-700 hover:bg-green-100' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                            <span class="material-icons-outlined text-base">{{ $service->is_active ? 'toggle_on' : 'toggle_off' }}</span>
                            {{ $service->is_active ? trans('nfse::general.settings.services.deactivate') : trans('nfse::general.settings.services.activate') }}
                        </button>
                    </form>

                </div>
            </div>
        </x-form.container>
    </x-slot>
</x-layouts.admin>
