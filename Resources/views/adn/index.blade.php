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
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                <h2 class="text-base font-semibold text-blue-900">{{ trans('nfse::general.adn.distribution_title') }}</h2>
                <p class="mt-1 text-sm text-blue-800">{{ trans('nfse::general.adn.distribution_help') }}</p>
            </div>

            <div
                id="adn-distribution-browser"
                data-url="{{ route('nfse.adn.distribution') }}"
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

        <script>
            (() => {
                const init = () => {
                    const browser = document.getElementById('adn-distribution-browser');
                    const queryButton = document.getElementById('adn-distribution-query');
                    const nsuInput = document.getElementById('adn-nsu');
                    const cnpjInput = document.getElementById('adn-cnpj');
                    const batchInput = document.getElementById('adn-lote');
                    const status = document.getElementById('adn-distribution-status');
                    const result = document.getElementById('adn-distribution-result');
                    const resultWrapper = document.getElementById('adn-distribution-result-wrapper');
                    const summary = document.getElementById('adn-distribution-summary');
                    const summaryStatus = document.getElementById('adn-summary-status');
                    const summaryDocuments = document.getElementById('adn-summary-documents');
                    const summaryMatched = document.getElementById('adn-summary-matched');
                    const summaryUnmatched = document.getElementById('adn-summary-unmatched');
                    const summaryLastNsu = document.getElementById('adn-summary-last-nsu');

                    if (
                        !(browser instanceof HTMLElement) ||
                        !(queryButton instanceof HTMLButtonElement) ||
                        !(nsuInput instanceof HTMLInputElement) ||
                        !(cnpjInput instanceof HTMLInputElement) ||
                        !(batchInput instanceof HTMLInputElement) ||
                        !(status instanceof HTMLElement) ||
                        !(result instanceof HTMLElement) ||
                        !(resultWrapper instanceof HTMLElement) ||
                        !(summary instanceof HTMLElement) ||
                        !(summaryStatus instanceof HTMLElement) ||
                        !(summaryDocuments instanceof HTMLElement) ||
                        !(summaryMatched instanceof HTMLElement) ||
                        !(summaryUnmatched instanceof HTMLElement) ||
                        !(summaryLastNsu instanceof HTMLElement)
                    ) {
                        return;
                    }

                    queryButton.addEventListener('click', async () => {
                        const url = browser.dataset.url ?? '';
                        const params = new URLSearchParams({
                            nsu: nsuInput.value.trim() || '0',
                            cnpj: cnpjInput.value.trim(),
                            lote: batchInput.checked ? '1' : '0',
                        });

                        queryButton.disabled = true;
                        status.textContent = @json((string) trans('nfse::general.adn.querying'));
                        result.textContent = '';
                        resultWrapper.classList.add('hidden');
                        summary.classList.add('hidden');

                        try {
                            const response = await fetch(`${url}?${params.toString()}`, {
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                status.textContent = typeof payload.message === 'string'
                                    ? payload.message
                                    : @json((string) trans('nfse::general.adn.distribution_query_failed'));
                                return;
                            }

                            const data = payload.data ?? {};
                            const documents = Array.isArray(data.documents) ? data.documents : [];

                            summaryStatus.textContent = String(data.status_processamento ?? '—');
                            summaryDocuments.textContent = String(documents.length);
                            summaryMatched.textContent = String(data.reconciliation?.matched ?? 0);
                            summaryUnmatched.textContent = String(data.reconciliation?.unmatched ?? documents.length);
                            summaryLastNsu.textContent = data.ultimo_nsu === null || data.ultimo_nsu === undefined
                                ? '—'
                                : String(data.ultimo_nsu);
                            summary.classList.remove('hidden');

                            result.textContent = JSON.stringify(data, null, 2);
                            resultWrapper.classList.remove('hidden');
                            status.textContent = '';
                        } catch (error) {
                            status.textContent = @json((string) trans('nfse::general.adn.distribution_query_failed'));
                        } finally {
                            queryButton.disabled = false;
                        }
                    });
                };

                if (document.readyState === 'complete') {
                    init();
                } else {
                    window.addEventListener('load', init, { once: true });
                }
            })();
        </script>
    </x-slot>
</x-layouts.admin>
