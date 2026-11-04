// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseFiscalTaxAdmin = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function errorMessage(responseStatus, payload) {
        if (responseStatus === 401 || responseStatus === 403) return 'Acesso não autorizado à configuração fiscal.';
        const details = payload && typeof payload.errors === 'object'
            ? Object.values(payload.errors).flat().filter((x) => typeof x === 'string') : [];
        return details[0] || (typeof payload?.message === 'string' && payload.message)
            || 'Não foi possível concluir a operação fiscal.';
    }

    async function getJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(errorMessage(response.status, payload));
        return payload;
    }

    async function postForm(fetchImpl, url, form) {
        const fields = new FormData(form);
        const payload = Object.fromEntries(fields.entries());
        delete payload._token;
        const response = await fetchImpl(url, {
            method: 'POST', credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': fields.get('_token') || '',
            },
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(errorMessage(response.status, result));
        return result;
    }

    function textElement(documentRef, tag, value, className = '') {
        const element = documentRef.createElement(tag);
        element.textContent = String(value ?? '');
        if (className) element.className = className;
        return element;
    }

    function fillOptions(documentRef, select, data, placeholder, label) {
        const previous = select.value;
        select.replaceChildren();
        const empty = textElement(documentRef, 'option', placeholder);
        empty.value = '';
        select.appendChild(empty);
        for (const entry of data) {
            const opt = textElement(documentRef, 'option', label(entry));
            opt.value = String(entry.value);
            select.appendChild(opt);
        }
        if (previous) select.value = previous;
    }

    function renderAssociations(documentRef, list, form, data) {
        if (!Array.isArray(data?.taxes) || !Array.isArray(data?.identities) || !Array.isArray(data?.roles)) {
            throw new Error('Formato inesperado do catálogo fiscal.');
        }
        // Read-only fiscal operators may list confirmed associations without a form.
        if (form) {
            const select = form.querySelector('[name="tax_id"]');
            fillOptions(documentRef, select, data.taxes.map((t) => ({
                value: t.id, name: t.name, type: t.type, rate: t.rate,
            })), 'Selecione um imposto desta empresa', (t) =>
                t.name + ' (#' + t.value + ') — ' + t.type + ' / ' + t.rate + '%');
            fillOptions(documentRef, form.querySelector('[name="fiscal_identity"]'),
                data.identities.map((value) => ({ value })), 'Selecione', (entry) => entry.value.toUpperCase());
            fillOptions(documentRef, form.querySelector('[name="fiscal_role"]'),
                data.roles.map((value) => ({ value })), 'Selecione', (entry) => entry.value);
        }

        if (form) form.hidden = data.taxes.length === 0;
        list.replaceChildren();
        if (!data.taxes.length) {
            const row = documentRef.createElement('tr');
            const cell = textElement(documentRef, 'td',
                'Nenhum imposto cadastrado nesta empresa. Não será criado imposto automaticamente.');
            cell.colSpan = 3;
            cell.className = 'px-3 py-4 text-gray-600';
            row.appendChild(cell);
            list.appendChild(row);
            return;
        }
        for (const tax of data.taxes) {
            const row = documentRef.createElement('tr');
            row.className = 'border-t';
            row.appendChild(textElement(documentRef, 'td', tax.name + ' (#' + tax.id + ')', 'px-3 py-2'));
            row.appendChild(textElement(documentRef, 'td', tax.type + ' / ' + tax.rate + '%', 'px-3 py-2'));
            const historyCell = documentRef.createElement('td');
            historyCell.className = 'px-3 py-2';
            if (!Array.isArray(tax.revisions) || tax.revisions.length === 0) {
                historyCell.appendChild(textElement(documentRef, 'span',
                    'Sem associação confirmada — identidade desconhecida', 'text-amber-800'));
            } else {
                const ul = documentRef.createElement('ul');
                ul.className = 'space-y-2';
                for (const rev of tax.revisions) {
                    const li = documentRef.createElement('li');
                    li.appendChild(textElement(documentRef, 'strong',
                        rev.fiscal_identity + ' / ' + rev.fiscal_role));
                    li.appendChild(textElement(documentRef, 'span',
                        ' · ' + rev.effective_from + ' a ' + (rev.effective_until || 'sem término')));
                    li.appendChild(textElement(documentRef, 'span',
                        'Fonte: ' + rev.source_reference + ' · Confirmado por #' + rev.confirmed_by
                        + (rev.confirmed_at ? ' em ' + rev.confirmed_at : ''),
                        'block text-xs text-gray-600'));
                    if (rev.type_mismatch) {
                        li.appendChild(textElement(documentRef, 'span',
                            'Revisar compatibilidade entre tipo contábil e papel fiscal.',
                            'block text-xs text-amber-800'));
                    }
                    ul.appendChild(li);
                }
                historyCell.appendChild(ul);
            }
            row.appendChild(historyCell);
            list.appendChild(row);
        }
    }

    function renderApproximate(documentRef, list, entries) {
        if (!Array.isArray(entries)) throw new Error('Formato inesperado das fontes fiscais.');
        list.replaceChildren();
        if (!entries.length) {
            const row = documentRef.createElement('tr');
            const cell = textElement(documentRef, 'td',
                'Nenhuma fonte cadastrada. A ausência será informada como desconhecida, nunca como 0%.');
            cell.colSpan = 4;
            row.appendChild(cell);
            list.appendChild(row);
            return;
        }
        for (const source of entries) {
            const row = documentRef.createElement('tr');
            row.className = 'border-t';
            row.appendChild(textElement(documentRef, 'td',
                source.national_service_code + ' / ' + source.simples_option));
            row.appendChild(textElement(documentRef, 'td',
                source.competence_from + ' — ' + (source.competence_until || '...')));
            const sourceCell = textElement(documentRef, 'td',
                source.source_name + ' / ' + source.source_version);
            sourceCell.appendChild(textElement(documentRef, 'span',
                source.source_reference, 'block text-xs'));
            row.appendChild(sourceCell);
            row.appendChild(textElement(documentRef, 'td',
                source.federal_percent + '% / ' + source.state_percent + '% / '
                + source.municipal_percent + '%'));
            list.appendChild(row);
        }
    }

    function init(documentRef, fetchImpl) {
        const taxRoot = documentRef.getElementById('nfse-tax-associations');
        const approximateRoot = documentRef.getElementById('nfse-approximate-taxes');
        if (!taxRoot && !approximateRoot) return false;
        const root = taxRoot || approximateRoot;
        const form = root.querySelector('form');
        const list = documentRef.getElementById(taxRoot ? 'tax-associations-body' : 'approximate-taxes-body');
        const feedback = documentRef.getElementById(taxRoot ? 'tax-association-feedback' : 'approximate-taxes-feedback');
        let page = 1;
        let lastPage = 1;
        const notify = (message) => {
            if (!feedback) return;
            feedback.textContent = message;
            feedback.hidden = !message;
        };
        const refresh = async () => {
            try {
                const url = new URL(root.dataset.listUrl, documentRef.baseURI);
                if (approximateRoot) url.searchParams.set('page', String(page));
                const payload = await getJson(fetchImpl, url.toString());
                if (taxRoot) {
                    renderAssociations(documentRef, list, form, payload.data);
                } else {
                    renderApproximate(documentRef, list, payload.data);
                    const meta = payload.meta || {};
                    page = Number(meta.current_page) || 1;
                    lastPage = Number(meta.last_page) || 1;
                    documentRef.getElementById('approximate-prev').disabled = page <= 1;
                    documentRef.getElementById('approximate-next').disabled = page >= lastPage;
                    documentRef.getElementById('approximate-page').textContent =
                        'Página ' + page + ' de ' + lastPage;
                }
            } catch (error) {
                const row = documentRef.createElement('tr');
                const cell = textElement(documentRef, 'td', error.message, 'px-3 py-3 text-red-700');
                cell.colSpan = taxRoot ? 3 : 4;
                row.appendChild(cell);
                list.replaceChildren(row);
                notify(error.message);
            }
        };
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            notify('');
            try {
                const response = await postForm(fetchImpl, root.dataset.storeUrl, form);
                notify(taxRoot
                    ? response.data?.already_confirmed ? 'Associação já confirmada.' : 'Associação fiscal confirmada.'
                    : 'Revisão de fonte fiscal registrada.');
                form.reset();
                page = 1;
                await refresh();
            } catch (error) {
                notify(error.message);
            } finally {
                button.disabled = false;
            }
        });
        if (approximateRoot) {
            documentRef.getElementById('approximate-prev').addEventListener('click', () => {
                if (page > 1) { page--; void refresh(); }
            });
            documentRef.getElementById('approximate-next').addEventListener('click', () => {
                if (page < lastPage) { page++; void refresh(); }
            });
        }
        void refresh();
        return true;
    }

    function boot(documentRef, fetchImpl) {
        if (!documentRef) return;
        const run = () => init(documentRef, fetchImpl);
        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    }
    return { init, boot, errorMessage, getJson, renderApproximate };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined' && globalThis.NfseFiscalTaxAdmin) {
    globalThis.NfseFiscalTaxAdmin.boot(document, fetch);
}
