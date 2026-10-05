// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseAdnDistributionBrowser = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function buildQuery(nsu, cnpj, batch) {
        return new URLSearchParams({
            nsu: String(nsu || '').trim() || '0',
            cnpj: String(cnpj || '').trim(),
            lote: batch ? '1' : '0',
        }).toString();
    }

    function summarize(data) {
        const source = data && typeof data === 'object' ? data : {};
        const documents = Array.isArray(source.documents) ? source.documents : [];

        return {
            status: String(source.status_processamento ?? '—'),
            documents: String(documents.length),
            matched: String(source.reconciliation?.matched ?? 0),
            unmatched: String(source.reconciliation?.unmatched ?? documents.length),
            lastNsu: source.ultimo_nsu === null || source.ultimo_nsu === undefined
                ? '—'
                : String(source.ultimo_nsu),
        };
    }

    function init(documentRef, fetchImpl) {
        const browser = documentRef.getElementById('adn-distribution-browser');
        const queryButton = documentRef.getElementById('adn-distribution-query');
        const nsuInput = documentRef.getElementById('adn-nsu');
        const cnpjInput = documentRef.getElementById('adn-cnpj');
        const batchInput = documentRef.getElementById('adn-lote');
        const status = documentRef.getElementById('adn-distribution-status');
        const result = documentRef.getElementById('adn-distribution-result');
        const resultWrapper = documentRef.getElementById('adn-distribution-result-wrapper');
        const summary = documentRef.getElementById('adn-distribution-summary');
        const summaryStatus = documentRef.getElementById('adn-summary-status');
        const summaryDocuments = documentRef.getElementById('adn-summary-documents');
        const summaryMatched = documentRef.getElementById('adn-summary-matched');
        const summaryUnmatched = documentRef.getElementById('adn-summary-unmatched');
        const summaryLastNsu = documentRef.getElementById('adn-summary-last-nsu');

        if (!browser || !queryButton || !nsuInput || !cnpjInput || !batchInput || !status
            || !result || !resultWrapper || !summary || !summaryStatus || !summaryDocuments
            || !summaryMatched || !summaryUnmatched || !summaryLastNsu) {
            return false;
        }

        queryButton.addEventListener('click', async () => {
            const url = browser.dataset.url || '';
            const queryingLabel = browser.dataset.queryingLabel || '';
            const errorLabel = browser.dataset.errorLabel || '';

            queryButton.disabled = true;
            status.textContent = queryingLabel;
            result.textContent = '';
            resultWrapper.classList.add('hidden');
            summary.classList.add('hidden');

            try {
                const response = await fetchImpl(
                    url + '?' + buildQuery(nsuInput.value, cnpjInput.value, batchInput.checked),
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    status.textContent = typeof payload.message === 'string'
                        ? payload.message
                        : errorLabel;
                    return;
                }

                const data = payload.data ?? {};
                const view = summarize(data);

                summaryStatus.textContent = view.status;
                summaryDocuments.textContent = view.documents;
                summaryMatched.textContent = view.matched;
                summaryUnmatched.textContent = view.unmatched;
                summaryLastNsu.textContent = view.lastNsu;
                summary.classList.remove('hidden');

                result.textContent = JSON.stringify(data, null, 2);
                resultWrapper.classList.remove('hidden');
                status.textContent = '';
            } catch (error) {
                status.textContent = errorLabel;
            } finally {
                queryButton.disabled = false;
            }
        });

        return true;
    }

    function boot(documentRef, fetchImpl) {
        if (!documentRef) {
            return;
        }

        const run = () => init(documentRef, fetchImpl);

        if (documentRef.readyState === 'complete') {
            run();
        } else if (typeof globalThis !== 'undefined' && globalThis.addEventListener) {
            globalThis.addEventListener('load', run, { once: true });
        }
    }

    return {
        buildQuery,
        init,
        summarize,
        boot,
    };
});

if (
    typeof document !== 'undefined'
    && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined'
    && globalThis.NfseAdnDistributionBrowser
) {
    globalThis.NfseAdnDistributionBrowser.boot(document, fetch);
}
