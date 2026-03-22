{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.readiness.title') }}</x-slot>

    <x-slot name="content">
        <div class="mb-4 flex flex-wrap gap-2">
            <a href="{{ route('nfse.dashboard.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_dashboard') }}
            </a>
            <a href="{{ route('nfse.settings.edit') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.go_to_settings') }}
            </a>
        </div>

        <div class="rounded border p-4 mb-4 {{ $isReady ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200' }}">
            <p class="font-semibold {{ $isReady ? 'text-green-700' : 'text-yellow-700' }}">
                {{ $isReady ? trans('nfse::general.readiness.ready') : trans('nfse::general.readiness.not_ready') }}
            </p>
            <p class="text-sm text-gray-600 mt-1">
                {{ trans('nfse::general.readiness.hint') }}
            </p>
        </div>

        <div class="bg-white border rounded overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.name') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ trans('general.status') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.cnpj_prestador') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['cnpj_prestador'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.municipio_ibge') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['municipio_ibge'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.item_lista_servico') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['item_lista_servico'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.bao_addr') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['bao_addr'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.bao_mount') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['bao_mount'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2">{{ trans('nfse::general.readiness.checks.certificate') }}</td>
                        <td class="px-4 py-2">{{ ($checklist['certificate'] ?? false) ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-slot>
</x-layouts.admin>
