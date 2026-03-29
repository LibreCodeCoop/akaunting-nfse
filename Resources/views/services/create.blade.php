{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.services.add_title') }}</x-slot>

    <x-slot name="content">
        <x-form.container>
            <form method="POST" action="{{ route('nfse.settings.services.store') }}">
                @csrf
                <x-form.section>
                    <x-slot name="head">
                        <x-form.section.head
                            title="{{ trans('nfse::general.settings.services.add_title') }}"
                            description="{{ trans('nfse::general.settings.services.description') }}"
                        />
                    </x-slot>

                    <x-slot name="body">
                        {{-- LC 116 code (plain HTML — avoids Vue v-model interference with autocomplete JS) --}}
                        <div class="relative sm:col-span-6 required">
                            <label for="item_lista_servico_display" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.lc116_code') }}
                                <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <input
                                type="text"
                                name="item_lista_servico_display"
                                id="item_lista_servico_display"
                                list="lc116_items"
                                autocomplete="off"
                                placeholder="Ex: 1.07 - Suporte tecnico em informatica"
                                value="{{ old('item_lista_servico_display') }}"
                                required
                                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                        </div>

                        <datalist id="lc116_items">
                            @foreach($items as $item)
                                <option value="{{ $item['label'] }}" data-code="{{ $item['code'] }}"></option>
                            @endforeach
                        </datalist>

                        <input id="item_lista_servico" name="item_lista_servico" type="hidden" value="{{ old('item_lista_servico') }}">

                        {{-- National tax code (NBS) --}}
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
                                value="{{ old('codigo_tributacao_nacional') }}"
                                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                            <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.settings.codigo_tributacao_nacional_hint') }}</p>
                            <p class="mt-1 text-xs">
                                <a href="https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs" target="_blank" rel="noopener noreferrer" class="text-blue-700 hover:text-blue-800 underline">
                                    Lista oficial de codigos de tributacao nacional (NBS)
                                </a>
                            </p>
                        </div>

                        {{-- Aliquota --}}
                        <div class="relative sm:col-span-3 required">
                            <label for="aliquota" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.aliquota') }}
                                <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <input
                                type="number"
                                name="aliquota"
                                id="aliquota"
                                step="0.01"
                                min="0"
                                max="100"
                                value="{{ old('aliquota', '5.00') }}"
                                required
                                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                        </div>

                        {{-- Is Active --}}
                        <div class="relative sm:col-span-3">
                            <label class="block text-sm font-medium mb-1">{{ trans('nfse::general.settings.services.status') }}</label>
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    {{ old('is_active', '1') ? 'checked' : '' }}
                                    class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                                >
                                <span class="text-sm text-gray-700">{{ trans('general.enabled') }}</span>
                            </label>
                        </div>

                        {{-- Description --}}
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
                            >{{ old('description') }}</textarea>
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
        </x-form.container>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const displayInput = document.getElementById('item_lista_servico_display');
                const hiddenInput = document.getElementById('item_lista_servico');
                const datalist = document.getElementById('lc116_items');

                if (!displayInput || !hiddenInput || !datalist) {
                    return;
                }

                const syncSelectedCode = () => {
                    const selectedText = displayInput.value;
                    const options = datalist.querySelectorAll('option');

                    hiddenInput.value = '';

                    for (const option of options) {
                        if (option.value === selectedText) {
                            hiddenInput.value = option.dataset.code ?? '';

                            return;
                        }
                    }
                };

                displayInput.addEventListener('change', syncSelectedCode);
                displayInput.addEventListener('blur', syncSelectedCode);

                // Keep Enter inside LC116 autocomplete from submitting the form prematurely.
                displayInput.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') {
                        return;
                    }

                    syncSelectedCode();
                    event.preventDefault();
                });

                displayInput.addEventListener('input', debounce(async (e) => {
                    const query = e.target.value.trim();

                    if (query.length === 0) {
                        return;
                    }

                    try {
                        const response = await fetch(`{{ route('nfse.lc116.services') }}?q=${encodeURIComponent(query)}`, {
                            headers: { Accept: 'application/json' },
                        });
                        const payload = await response.json();
                        const services = Array.isArray(payload.data) ? payload.data : [];

                        datalist.innerHTML = '';

                        services.forEach((item) => {
                            const option = document.createElement('option');
                            option.value = item.label;
                            option.dataset.code = item.code;
                            datalist.appendChild(option);
                        });

                        syncSelectedCode();
                    } catch (error) {
                        console.error('Error fetching LC 116 items:', error);
                    }
                }, 300));
            });

            function debounce(fn, delay) {
                let timeoutId;

                return function(...args) {
                    clearTimeout(timeoutId);
                    timeoutId = setTimeout(() => fn(...args), delay);
                };
            }
        </script>
    </x-slot>
</x-layouts.admin>
