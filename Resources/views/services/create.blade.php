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

                        <div class="relative sm:col-span-6">
                            <label for="item_ids_select" class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.linked_items') }}
                            </label>
                            <select id="item_ids_select" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">{{ trans('general.select') }}</option>
                                @foreach(($companyItems ?? collect()) as $companyItem)
                                    <option value="{{ $companyItem->id }}">
                                        {{ $companyItem->name }}
                                        @if(!empty($companyItem->type))
                                            ({{ $companyItem->type }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.settings.services.linked_items_hint') }}</p>
                            <div class="mt-3">
                                <p class="mb-2 text-sm font-medium text-gray-700">{{ trans('nfse::general.settings.services.selected_items_label') }}</p>
                                <div id="item_ids_selected_list" class="flex flex-wrap gap-2"></div>
                            </div>
                            <div id="item_ids_hidden_inputs">
                                @foreach(array_map('intval', old('item_ids', $selectedItemIds ?? [])) as $selectedItemId)
                                    <input type="hidden" name="item_ids[]" value="{{ $selectedItemId }}">
                                @endforeach
                            </div>
                        </div>

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
                            <div class="relative">
                                <input
                                    type="number"
                                    name="aliquota"
                                    id="aliquota"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    value="{{ old('aliquota', '5.00') }}"
                                    required
                                    class="w-full border rounded-lg px-3 py-2 pr-12 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                <span data-tax-affix="percent" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400">%</span>
                            </div>
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

                const itemSelect = document.getElementById('item_ids_select');
                const selectedList = document.getElementById('item_ids_selected_list');
                const hiddenInputs = document.getElementById('item_ids_hidden_inputs');

                if (!itemSelect || !selectedList || !hiddenInputs) {
                    return;
                }

                const selectedIds = Array.from(new Set(Array.from(hiddenInputs.querySelectorAll('input[name="item_ids[]"]')).map((input) => String(input.value))));

                const renderSelectedItems = () => {
                    hiddenInputs.innerHTML = '';
                    selectedList.innerHTML = '';

                    for (const option of itemSelect.options) {
                        if (option.value === '') {
                            continue;
                        }

                        option.disabled = selectedIds.includes(option.value);
                    }

                    if (selectedIds.length === 0) {
                        const emptyState = document.createElement('p');
                        emptyState.className = 'rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-2 text-xs text-gray-500';
                        emptyState.textContent = @json(trans('nfse::general.settings.services.no_linked_items_selected'));
                        selectedList.appendChild(emptyState);

                        return;
                    }

                    selectedIds.forEach((itemId) => {
                        const option = itemSelect.querySelector(`option[value="${itemId}"]`);
                        const label = option ? option.textContent.trim() : itemId;

                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'item_ids[]';
                        hidden.value = itemId;
                        hiddenInputs.appendChild(hidden);

                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-2 rounded-full border border-gray-300 bg-gray-200 px-3 py-1 text-xs font-medium text-gray-800';
                        chip.textContent = label;

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-red-700 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-red-300';
                        removeButton.setAttribute('aria-label', @json(trans('nfse::general.settings.services.remove_linked_item')));
                        removeButton.dataset.removeId = itemId;
                        removeButton.textContent = 'X';

                        chip.appendChild(removeButton);
                        selectedList.appendChild(chip);
                    });
                };

                itemSelect.addEventListener('change', () => {
                    const itemId = itemSelect.value;

                    if (itemId === '' || selectedIds.includes(itemId)) {
                        itemSelect.value = '';
                        itemSelect.blur();

                        return;
                    }

                    selectedIds.push(itemId);
                    itemSelect.value = '';
                    itemSelect.blur();
                    renderSelectedItems();
                });

                selectedList.addEventListener('click', (event) => {
                    const button = event.target.closest('button[data-remove-id]');

                    if (!button) {
                        return;
                    }

                    const removeId = String(button.dataset.removeId || '');
                    const index = selectedIds.indexOf(removeId);

                    if (index === -1) {
                        return;
                    }

                    selectedIds.splice(index, 1);
                    renderSelectedItems();
                });

                renderSelectedItems();
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
