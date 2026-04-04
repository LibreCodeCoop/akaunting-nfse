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
                            <label class="block text-sm font-medium mb-1">
                                {{ trans('nfse::general.settings.services.lc116_code') }}
                            </label>
                            <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                                {{ $service->display_name }}
                            </div>
                        </div>

                        <input id="item_lista_servico" name="item_lista_servico" type="hidden" value="{{ old('item_lista_servico', $service->item_lista_servico) }}">

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

        <script>
            document.addEventListener('DOMContentLoaded', () => {
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
        </script>

    </x-slot>
</x-layouts.admin>
