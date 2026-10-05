// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseInvoiceListSearch = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function baseUrl(currentUrl) {
        const url = new URL(String(currentUrl), 'http://localhost');

        return url.origin === 'http://localhost'
            ? url.pathname
            : url.origin + url.pathname;
    }

    function clearSearchUrl(currentUrl) {
        return baseUrl(currentUrl) + '?search=';
    }

    function mergeSearchCookie(currentUrl, existing, filters) {
        const merged = existing && typeof existing === 'object' && !Array.isArray(existing)
            ? { ...existing }
            : {};

        merged[baseUrl(currentUrl)] = filters && typeof filters === 'object' ? filters : {};

        return merged;
    }

    function parseCookieValue(raw) {
        if (!raw) {
            return {};
        }

        try {
            const parsed = JSON.parse(raw);

            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch {
            return {};
        }
    }

    function readRawCookie(documentRef, name) {
        const cookies = documentRef.cookie ? documentRef.cookie.split('; ') : [];

        for (const item of cookies) {
            const separatorIndex = item.indexOf('=');

            if (separatorIndex === -1) {
                continue;
            }

            const key = item.slice(0, separatorIndex);
            const value = item.slice(separatorIndex + 1);

            if (key === name) {
                return decodeURIComponent(value);
            }
        }

        return null;
    }

    function boot(documentRef, locationRef, cookiesApi, filters) {
        if (!documentRef || !locationRef) {
            return;
        }

        documentRef.addEventListener('click', (event) => {
            const clearButton = event.target && event.target.closest
                ? event.target.closest('.clear')
                : null;

            if (!clearButton || !clearButton.closest('.js-search')) {
                return;
            }

            event.stopImmediatePropagation();
            event.preventDefault();

            if (cookiesApi && typeof cookiesApi.remove === 'function') {
                cookiesApi.remove('search-string');
            }

            locationRef.href = clearSearchUrl(locationRef.href);
        }, true);

        if (!filters || Object.keys(filters).length === 0) {
            return;
        }

        const rawCookie = cookiesApi && typeof cookiesApi.get === 'function'
            ? cookiesApi.get('search-string')
            : readRawCookie(documentRef, 'search-string');
        const value = mergeSearchCookie(locationRef.href, parseCookieValue(rawCookie), filters);

        if (cookiesApi && typeof cookiesApi.set === 'function') {
            cookiesApi.set('search-string', value);

            return;
        }

        documentRef.cookie = 'search-string=' + encodeURIComponent(JSON.stringify(value)) + '; path=/';
    }

    return {
        baseUrl,
        boot,
        clearSearchUrl,
        mergeSearchCookie,
        parseCookieValue,
    };
});

if (
    typeof document !== 'undefined'
    && typeof location !== 'undefined'
    && typeof globalThis !== 'undefined'
    && globalThis.NfseInvoiceListSearch
) {
    const configNode = document.getElementById('nfse-invoice-list-search-config');
    let filters = {};

    try {
        filters = JSON.parse(configNode?.textContent || '{}');
    } catch {
        filters = {};
    }

    globalThis.NfseInvoiceListSearch.boot(
        document,
        location,
        typeof Cookies !== 'undefined' ? Cookies : null,
        filters,
    );
}
