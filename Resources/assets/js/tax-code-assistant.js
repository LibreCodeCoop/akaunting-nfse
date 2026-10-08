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
    const digits = value => String(value ?? '').replace(/\D/g, '');
    const lcForNational = code => digits(code).length === 6 ? 'lc:' + digits(code).slice(0, 4) : '';
    const entriesFor = (national, lcValue, query) => {
        const prefix = digits(lcValue).slice(0, 4);
        const term = String(query ?? '').trim().toLocaleLowerCase('pt-BR');
        return Object.entries(national).filter(([code, label]) =>
            (!prefix || code.startsWith(prefix)) &&
            (!term || (code + ' ' + label).toLocaleLowerCase('pt-BR').includes(term))
        );
    };
    function init(doc) {
        const root = doc.querySelector('[data-nfse-tax-code-assistant]');
        if (!root || root.dataset.initialized === 'true') return;
        const lc = JSON.parse(root.dataset.lcOptions || '{}');
        const national = JSON.parse(root.dataset.nationalOptions || '{}');
        const service = doc.querySelector('[name="nfse_item_lista_servico"]');
        const code = doc.querySelector('[name="nfse_codigo_tributacao_nacional"]');
        const query = root.querySelector('[data-nfse-tax-code-query]');
        const results = root.querySelector('[data-nfse-tax-code-results]');
        const summary = root.querySelector('[data-nfse-tax-code-summary]');
        if (!service || !code || !query || !results || !summary) return;
        root.dataset.initialized = 'true';
        function setSelect(select, value) {
            if (!Array.from(select.options).some(option => option.value === value)) return false;
            select.value = value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
        function render() {
            const options = entriesFor(national, service.value, query.value);
            summary.textContent = options.length + ' ' + root.dataset.matchesLabel;
            results.replaceChildren();
            for (const [value, label] of options.slice(0, 60)) {
                const button = doc.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded px-2 py-2 text-left text-sm hover:bg-gray-100 focus:outline focus:outline-2';
                button.textContent = label;
                button.setAttribute('aria-pressed', String(value === code.value));
                button.addEventListener('click', () => {
                    setSelect(code, value);
                    const related = lcForNational(value);
                    if (lc[related]) setSelect(service, related);
                    render();
                });
                results.append(button);
            }
        }
        query.addEventListener('input', render);
        service.addEventListener('change', render);
        code.addEventListener('change', () => {
            const related = lcForNational(code.value);
            if (lc[related]) setSelect(service, related);
            render();
        });
        render();
    }
    return { digits, lcForNational, entriesFor, init };
});
