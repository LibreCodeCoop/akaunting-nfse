// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseTaxCodeAssistant = api;
    if (root && root.document) {
        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', () => api.init(root.document));
        } else {
            api.init(root.document);
        }
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const digits = value => String(value ?? '').replace(/\\D/g, '');
    const lcForNational = code => digits(code).length === 6 ? 'lc:' + digits(code).slice(0, 4) : '';
    function debounce(fn, delay, schedule = setTimeout, clear = clearTimeout) {
        let timeout;
        return (...args) => {
            if (timeout !== undefined) clear(timeout);
            timeout = schedule(() => { timeout = undefined; fn(...args); }, delay);
        };
    }
    function createSearch(fetcher) {
        let sequence = 0;
        let pending;
        return async (url, params) => {
            const current = ++sequence;
            if (pending) pending.abort();
            pending = new AbortController();
            try {
                const query = new URLSearchParams(params);
                const response = await fetcher(url + '?' + query.toString(), { signal: pending.signal });
                if (!response.ok) throw new Error('Search failed');
                const body = await response.json();
                return current === sequence ? body.data : null;
            } catch (error) {
                if (current !== sequence || error.name === 'AbortError') return null;
                throw error;
            }
        };
    }
    function init(doc, fetcher = fetch) {
        const root = doc.querySelector('[data-nfse-tax-code-assistant]');
        if (!root || root.dataset.initialized === 'true') return;
        const service = doc.querySelector('[name="nfse_item_lista_servico"]');
        const national = doc.querySelector('[name="nfse_codigo_tributacao_nacional"]');
        const input = root.querySelector('[data-nfse-tax-code-query]');
        const results = root.querySelector('[data-nfse-tax-code-results]');
        const summary = root.querySelector('[data-nfse-tax-code-summary]');
        if (!service || !national || !input || !results || !summary) return;
        const lcOptions = JSON.parse(root.dataset.lcOptions || '{}');
        const search = createSearch(fetcher);
        let revision = 0;
        root.dataset.initialized = 'true';

        function updateSelect(select, value, label) {
            if (!Array.from(select.options).some(option => option.value === value)) {
                select.add(new Option(label, value));
            }
            select.value = value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function render(entries) {
            results.replaceChildren();
            summary.textContent = entries.length + ' ' + root.dataset.matchesLabel;
            for (const entry of entries) {
                const button = doc.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded px-2 py-2 text-left text-sm hover:bg-gray-100 focus:outline focus:outline-2';
                button.textContent = entry.code + ' - ' + entry.description;
                button.addEventListener('click', () => {
                    updateSelect(national, entry.code, button.textContent);
                    const related = lcForNational(entry.code);
                    if (lcOptions[related]) updateSelect(service, related, lcOptions[related]);
                });
                results.append(button);
            }
        }
        async function refresh() {
            const current = ++revision;
            try {
                const entries = await search(root.dataset.searchUrl, {
                    lc116: digits(service.value).slice(0, 4),
                    q: input.value.trim(),
                });
                if (current === revision && entries !== null) render(entries);
            } catch (error) {
                if (current === revision) {
                    results.replaceChildren();
                    summary.textContent = root.dataset.errorLabel || '';
                }
            }
        }
        const scheduled = debounce(refresh, 300);
        input.addEventListener('input', scheduled);
        service.addEventListener('change', scheduled);
        national.addEventListener('change', () => {
            const related = lcForNational(national.value);
            if (lcOptions[related] && service.value !== related) updateSelect(service, related, lcOptions[related]);
        });
        if (digits(service.value).length === 4) scheduled();
    }
    return { digits, lcForNational, debounce, createSearch, init };
});
