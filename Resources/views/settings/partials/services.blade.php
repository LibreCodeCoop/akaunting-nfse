{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<section class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">{{ trans('nfse::general.settings.services.title') }}</h3>
    <p class="text-sm text-gray-600">{{ trans('nfse::general.settings.services.description') }}</p>
    <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        {{ trans('nfse::general.settings.services.item_tax_scope_notice') }}
    </div>
    <p class="text-xs text-gray-500">
        <a href="https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm" target="_blank" rel="noopener noreferrer" class="text-blue-700 hover:text-blue-800 underline">
            {{ trans('nfse::general.settings.services.lc116_code') }}
        </a>
    </p>

    <form id="services-filter-form" method="GET" action="{{ route('nfse.settings.edit') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <input type="hidden" name="tab" value="services">

        <div class="md:col-span-2">
            <label for="services-search" class="block text-sm font-medium mb-1">{{ trans('general.search') }}</label>
            <input
                id="services-search"
                name="services_search"
                type="text"
                value="{{ $servicesSearch ?? '' }}"
                placeholder="{{ trans('nfse::general.settings.services.filter_placeholder') }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label for="services-status" class="block text-sm font-medium mb-1">{{ trans('nfse::general.settings.services.status') }}</label>
            <select id="services-status" name="services_status" class="w-full border rounded px-3 py-2">
                <option value="all" @selected(($servicesStatus ?? 'all') === 'all')>{{ trans('nfse::general.settings.services.filter_status_all') }}</option>
                <option value="active" @selected(($servicesStatus ?? 'all') === 'active')>{{ trans('general.enabled') }}</option>
                <option value="inactive" @selected(($servicesStatus ?? 'all') === 'inactive')>{{ trans('general.disabled') }}</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button id="services-filter-apply" type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                {{ trans('nfse::general.settings.services.filter_apply') }}
            </button>
            <a id="services-filter-clear" href="{{ route('nfse.settings.edit', ['tab' => 'services']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-100">
                {{ trans('nfse::general.settings.services.filter_clear') }}
            </a>
        </div>
    </form>

    {{-- Services Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-gray-900">
            <thead class="border-b border-gray-200">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">{{ trans('nfse::general.settings.services.lc116_code') }}</th>
                    <th class="px-4 py-2 text-left font-semibold">{{ trans('nfse::general.settings.services.national_tax_code') }}</th>
                    <th class="px-4 py-2 text-left font-semibold">{{ trans('nfse::general.settings.services.service_description') }}</th>
                    <th class="px-4 py-2 text-left font-semibold">{{ trans('nfse::general.settings.services.aliquota') }}</th>
                    <th class="px-4 py-2 text-center font-semibold">{{ trans('nfse::general.settings.services.default') }}</th>
                    <th class="px-4 py-2 text-center font-semibold">{{ trans('nfse::general.settings.services.status') }}</th>
                    <th class="px-4 py-2 text-center font-semibold">{{ trans('general.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companyServices as $service)
                    @php($rowDescription = $service->description ?: '-')
                    <tr class="border-b border-gray-200 hover:bg-gray-50" data-service-code="{{ $service->item_lista_servico }}">
                        <td class="px-4 py-2 font-medium">{{ $service->display_name }}</td>
                        <td class="px-4 py-2 font-medium">{{ $service->codigo_tributacao_nacional ?: '-' }}</td>
                        <td class="px-4 py-2 text-gray-700">{{ $rowDescription }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($service->aliquota, 2, ',', '.') }}%</td>
                        <td class="px-4 py-2 text-center">
                            @if($service->is_default)
                                <span class="material-icons-outlined text-yellow-500 text-lg">star</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $service->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                                {{ $service->is_active ? trans('general.enabled') : trans('general.disabled') }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center">
                            <div class="inline-flex items-center overflow-hidden rounded-xl border border-gray-300 bg-white">
                                {{-- Make default --}}
                                @if($service->is_default)
                                    <span class="px-2 py-1 border-r border-gray-300 cursor-default" title="{{ trans('nfse::general.settings.services.is_default') }}">
                                        <span class="material-icons-outlined text-yellow-500 text-lg">star</span>
                                    </span>
                                @elseif($service->is_active)
                                    <form method="POST" action="{{ route('nfse.settings.services.make-default', $service->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 hover:bg-gray-100 border-r border-gray-300" title="{{ trans('nfse::general.settings.services.make_default') }}" aria-label="{{ trans('nfse::general.settings.services.make_default') }}">
                                            <span class="material-icons-outlined text-gray-400 text-lg">star_border</span>
                                        </button>
                                    </form>
                                @else
                                    <span class="px-2 py-1 border-r border-gray-300 opacity-30 cursor-default" title="{{ trans('nfse::general.settings.services.make_default') }}">
                                        <span class="material-icons-outlined text-gray-400 text-lg">star_border</span>
                                    </span>
                                @endif

                                {{-- Toggle active --}}
                                <form method="POST" action="{{ route('nfse.settings.services.toggle-active', $service->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="px-2 py-1 hover:bg-gray-100 border-r border-gray-300" title="{{ $service->is_active ? trans('nfse::general.settings.services.deactivate') : trans('nfse::general.settings.services.activate') }}" aria-label="{{ $service->is_active ? trans('nfse::general.settings.services.deactivate') : trans('nfse::general.settings.services.activate') }}">
                                        <span class="material-icons-outlined {{ $service->is_active ? 'text-green-600' : 'text-gray-400' }} text-lg">
                                            {{ $service->is_active ? 'toggle_on' : 'toggle_off' }}
                                        </span>
                                    </button>
                                </form>

                                {{-- Edit --}}
                                <a href="{{ route('nfse.settings.services.edit', $service->id) }}" class="px-2 py-1 hover:bg-gray-100 border-r border-gray-300" title="{{ trans('general.edit') }}" aria-label="{{ trans('general.edit') }}">
                                    <span class="material-icons-outlined text-purple text-lg">edit</span>
                                </a>

                                {{-- Delete --}}
                                <form method="POST" action="{{ route('nfse.settings.services.destroy', $service->id) }}" onsubmit="return confirm('{{ trans('general.sure') }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-2 py-1 hover:bg-gray-100" title="{{ trans('general.delete') }}" aria-label="{{ trans('general.delete') }}">
                                        <span class="material-icons-outlined text-purple text-lg">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-4 text-center text-gray-500">
                            {{ trans('nfse::general.settings.services.no_services') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add New Service Button --}}
    <div class="flex justify-end">
        <a href="{{ route('nfse.settings.services.create') }}" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
            {{ trans('nfse::general.settings.services.add_service') }}
        </a>
    </div>

    <div class="pt-6 border-t border-gray-200">
        <h4 class="text-base font-semibold text-gray-900">{{ trans('nfse::general.settings.services.item_mapping_title') }}</h4>
        <p class="text-sm text-gray-600 mt-1">{{ trans('nfse::general.settings.services.item_mapping_description') }}</p>

        @if(empty($companyItems))
            <p class="mt-4 text-sm text-gray-500">{{ trans('nfse::general.settings.services.no_items_for_mapping') }}</p>
        @else
            <form method="POST" action="{{ route('nfse.settings.item-services.update') }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-gray-900">
                        <thead class="border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">{{ trans_choice('general.items', 1) }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ trans('general.type') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ trans('nfse::general.settings.services.linked_service') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($companyItems as $item)
                                @php($selectedServiceId = old('item_services.' . $item->id, $itemServiceMappings[$item->id]->company_service_id ?? ''))
                                <tr class="border-b border-gray-100">
                                    <td class="px-4 py-2 font-medium">{{ $item->name }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ (string) ($item->type ?? 'item') }}</td>
                                    <td class="px-4 py-2">
                                        <select name="item_services[{{ $item->id }}]" class="w-full border rounded px-3 py-2">
                                            <option value="">{{ trans('nfse::general.settings.services.no_linked_service') }}</option>
                                            @foreach($companyServices as $service)
                                                <option value="{{ $service->id }}" @selected((string) $selectedServiceId === (string) $service->id)>
                                                    {{ $service->display_name }}
                                                    @if($service->is_default)
                                                        [default]
                                                    @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                        {{ trans('nfse::general.settings.services.save_item_mappings') }}
                    </button>
                </div>
            </form>
        @endif
    </div>
</section>
