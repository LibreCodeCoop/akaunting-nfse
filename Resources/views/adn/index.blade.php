{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.adn.title') }}</x-slot>

    <x-slot name="buttons">
        <x-link href="{{ route('nfse.invoices.index') }}">
            {{ trans('nfse::general.invoices.back_to_list') }}
        </x-link>
    </x-slot>

    <x-slot name="content">
        <div class="max-w-5xl space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5" aria-labelledby="adn-accounting-review-title">
                <h2 id="adn-accounting-review-title" class="text-base font-semibold text-gray-900">
                    {{ trans('nfse::general.adn.review_title') }}
                </h2>
                <p class="mt-1 text-sm text-gray-600">{{ trans('nfse::general.adn.review_help') }}</p>

                <div class="mt-4 space-y-3">
                    @forelse(($reviewDocuments ?? []) as $reviewRow)
                        @php
                            $reviewDocument = $reviewRow['document'];
                            $accountingPreview = $reviewRow['preview'];
                        @endphp
                        <article class="rounded border border-gray-200 p-4" data-adn-review-document="{{ $reviewDocument->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-gray-900">
                                        {{ $accountingPreview['supplier_name'] ?? trans('nfse::general.adn.review_unknown_supplier') }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $reviewDocument->chave_acesso ?: $reviewDocument->document_key }}
                                    </div>
                                </div>
                                <span class="rounded bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                                    {{ $reviewDocument->fiscal_role }}
                                </span>
                            </div>

                            @if(is_array($accountingPreview))
                                <dl class="mt-3 grid gap-2 text-sm md:grid-cols-3">
                                    <div>
                                        <dt class="text-gray-500">{{ trans('nfse::general.adn.review_competence') }}</dt>
                                        <dd>{{ $accountingPreview['competence'] ?: '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">{{ trans('nfse::general.adn.review_gross') }}</dt>
                                        <dd>{{ $accountingPreview['gross_value'] ?: '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">{{ trans('nfse::general.adn.review_liquid') }}</dt>
                                        <dd>{{ $accountingPreview['liquid_value'] ?: '—' }}</dd>
                                    </div>
                                    <div class="md:col-span-3">
                                        <dt class="text-gray-500">{{ trans('nfse::general.adn.review_service') }}</dt>
                                        <dd>{{ $accountingPreview['service_description'] ?: '—' }}</dd>
                                    </div>
                                </dl>
                            @else
                                <p class="mt-3 text-sm text-red-700">{{ trans('nfse::general.adn.review_invalid_xml') }}</p>
                            @endif

                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @if(is_array($accountingPreview))
                                    <form method="POST" action="{{ route('nfse.adn.review.import', $reviewDocument->id) }}" class="space-y-3 rounded border border-gray-200 p-3">
                                        @csrf

                                        <div>
                                            <label for="adn-vendor-{{ $reviewDocument->id }}" class="block text-sm font-medium">
                                                {{ trans('nfse::general.adn.review_vendor') }}
                                            </label>
                                            <select id="adn-vendor-{{ $reviewDocument->id }}" name="contact_id" class="mt-1 w-full rounded border px-3 py-2">
                                                <option value="0">{{ trans('nfse::general.adn.review_vendor_create_option') }}</option>
                                                @foreach(($reviewVendors ?? []) as $vendor)
                                                    <option value="{{ $vendor->id }}">
                                                        {{ $vendor->name }}@if($vendor->tax_number) — {{ $vendor->tax_number }}@endif
                                                    </option>
                                                @endforeach
                                            </select>
                                            <label class="mt-2 inline-flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" name="create_vendor" value="1">
                                                {{ trans('nfse::general.adn.review_create_vendor') }}
                                            </label>
                                        </div>

                                        <div>
                                            <label for="adn-category-{{ $reviewDocument->id }}" class="block text-sm font-medium">
                                                {{ trans('nfse::general.adn.review_category') }}
                                            </label>
                                            <select id="adn-category-{{ $reviewDocument->id }}" name="category_id" required class="mt-1 w-full rounded border px-3 py-2">
                                                <option value="">{{ trans('nfse::general.adn.review_select') }}</option>
                                                @foreach(($reviewCategories ?? []) as $category)
                                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div>
                                            <label for="adn-item-{{ $reviewDocument->id }}" class="block text-sm font-medium">
                                                {{ trans('nfse::general.adn.review_item') }}
                                            </label>
                                            <select id="adn-item-{{ $reviewDocument->id }}" name="item_id" required class="mt-1 w-full rounded border px-3 py-2">
                                                <option value="">{{ trans('nfse::general.adn.review_select') }}</option>
                                                @foreach(($reviewItems ?? []) as $item)
                                                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label for="adn-issued-{{ $reviewDocument->id }}" class="block text-sm font-medium">
                                                    {{ trans('nfse::general.adn.review_issued_at') }}
                                                </label>
                                                <input id="adn-issued-{{ $reviewDocument->id }}" type="date" name="issued_at" value="{{ $accountingPreview['competence'] ?? '' }}" required class="mt-1 w-full rounded border px-3 py-2">
                                            </div>
                                            <div>
                                                <label for="adn-due-{{ $reviewDocument->id }}" class="block text-sm font-medium">
                                                    {{ trans('nfse::general.adn.review_due_at') }}
                                                </label>
                                                <input id="adn-due-{{ $reviewDocument->id }}" type="date" name="due_at" value="{{ $accountingPreview['competence'] ?? '' }}" required class="mt-1 w-full rounded border px-3 py-2">
                                            </div>
                                        </div>

                                        <p class="text-xs text-gray-600">
                                            {{ trans('nfse::general.adn.review_import_notice') }}
                                        </p>

                                        <button type="submit" class="rounded bg-green-700 px-3 py-2 text-sm font-medium text-white">
                                            {{ trans('nfse::general.adn.review_import_draft') }}
                                        </button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('nfse.adn.review.ignore', $reviewDocument->id) }}" class="self-end">
                                    @csrf
                                    <button type="submit" class="rounded border px-3 py-2 text-sm">
                                        {{ trans('nfse::general.adn.review_ignore') }}
                                    </button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">{{ trans('nfse::general.adn.review_empty') }}</p>
                    @endforelse
                </div>
            </section>

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                <h2 id="adn-distribution-title" class="text-base font-semibold text-blue-900">{{ trans('nfse::general.adn.distribution_title') }}</h2>
                <p class="mt-1 text-sm text-blue-800">{{ trans('nfse::general.adn.distribution_help') }}</p>
            </div>

            <div
                id="adn-distribution-browser"
                data-url="{{ route('nfse.adn.distribution') }}"
                data-querying-label="{{ trans('nfse::general.adn.querying') }}"
                data-error-label="{{ trans('nfse::general.adn.distribution_query_failed') }}"
                role="region"
                aria-labelledby="adn-distribution-title"
                class="rounded-lg border border-gray-200 bg-white p-5 space-y-4"
            >
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="adn-nsu" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.adn.nsu') }}</label>
                        <input id="adn-nsu" type="number" min="0" value="0" class="w-full rounded border border-gray-300 px-3 py-2">
                    </div>

                    <div>
                        <label for="adn-cnpj" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.adn.cnpj') }}</label>
                        <input id="adn-cnpj" type="text" maxlength="18" value="{{ setting('nfse.cnpj_prestador', '') }}" class="w-full rounded border border-gray-300 px-3 py-2">
                    </div>

                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700">
                            <input id="adn-lote" type="checkbox" value="1" checked>
                            <span>{{ trans('nfse::general.adn.batch') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button id="adn-distribution-query" type="button" class="inline-flex items-center rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
                        {{ trans('nfse::general.adn.query') }}
                    </button>
                    <span id="adn-distribution-status" class="text-sm text-gray-600" aria-live="polite"></span>
                </div>

                <div id="adn-distribution-summary" class="hidden grid grid-cols-1 md:grid-cols-5 gap-3">
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.adn.status') }}</p>
                        <p id="adn-summary-status" class="font-semibold text-gray-800">—</p>
                    </div>
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.adn.documents') }}</p>
                        <p id="adn-summary-documents" class="font-semibold text-gray-800">0</p>
                    </div>
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.adn.matched_local') }}</p>
                        <p id="adn-summary-matched" class="font-semibold text-gray-800">0</p>
                    </div>
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.adn.unmatched_local') }}</p>
                        <p id="adn-summary-unmatched" class="font-semibold text-gray-800">0</p>
                    </div>
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs uppercase text-gray-500">{{ trans('nfse::general.adn.last_nsu') }}</p>
                        <p id="adn-summary-last-nsu" class="font-semibold text-gray-800">—</p>
                    </div>
                </div>

                <div id="adn-distribution-result-wrapper" class="hidden">
                    <p class="mb-2 text-sm font-medium text-gray-700">{{ trans('nfse::general.adn.response') }}</p>
                    <pre id="adn-distribution-result" class="max-h-[60vh] overflow-auto rounded bg-gray-900 p-4 text-xs text-gray-100 whitespace-pre-wrap"></pre>
                </div>
            </div>
        </div>

        <script
            src="{{ asset('modules/Nfse/Resources/assets/js/adn-distribution-browser.js?v=' . module_version('nfse')) }}"
            data-nfse-adn-distribution-module="true"
        ></script>

    </x-slot>
</x-layouts.admin>
