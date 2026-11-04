// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.NfseAdnReviewBrowser = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function urlFor(template, documentId) {
        const id = Number(documentId);
        if (!Number.isSafeInteger(id) || id < 1 || !template.includes('__DOCUMENT_ID__')) {
            throw new Error('Invalid ADN review document.');
        }
        return template.replace('__DOCUMENT_ID__', String(id));
    }

    function eligibleRows(rows) {
        if (!Array.isArray(rows)) {
            return [];
        }
        return rows.filter((row) => row && row.review_status === 'pending'
            && ['received', 'intermediated'].includes(row.fiscal_role)
            && Number.isSafeInteger(Number(row.id)) && Number(row.id) > 0);
    }

    async function getJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(typeof payload.message === 'string' ? payload.message : 'ADN request failed.');
        }
        return payload.data;
    }

    function addOptions(documentRef, select, values) {
        if (!select || !Array.isArray(values)) {
            return;
        }
        for (const value of values) {
            if (!value || !Number.isSafeInteger(Number(value.id)) || Number(value.id) < 1) {
                continue;
            }
            const option = documentRef.createElement('option');
            option.value = String(value.id);
            option.textContent = String(value.name ?? '');
            select.appendChild(option);
        }
    }

    function setText(node, selector, text) {
        const field = node.querySelector(selector);
        if (field) {
            field.textContent = text === null || text === undefined || text === '' ? '—' : String(text);
        }
    }

    function bindForm(documentRef, form, fetchImpl, navigate, reload) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]');
            const status = documentRef.createElement('p');
            status.setAttribute('role', 'alert');
            status.className = 'text-sm text-red-700';
            status.dataset.adnError = 'true';
            form.querySelector('[data-adn-error]')?.remove();
            if (submitButton) {
                submitButton.disabled = true;
            }
            try {
                const response = await fetchImpl(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });
                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(typeof payload.message === 'string'
                        ? payload.message : 'ADN review request failed.');
                }
                if (form.dataset.adnJsonAction === 'import') {
                    const url = payload.data?.redirect_url;
                    if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) {
                        throw new Error('Invalid bill URL in import response.');
                    }
                    navigate(url);
                } else {
                    reload();
                }
            } catch (error) {
                status.textContent = error instanceof Error ? error.message : 'ADN review request failed.';
                form.appendChild(status);
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }

    function renderReview(documentRef, template, list, row, options, fetchImpl, navigate, reload) {
        const article = template.content.firstElementChild.cloneNode(true);
        article.dataset.adnReviewDocument = String(row.id);
        const preview = row.accounting_preview && typeof row.accounting_preview === 'object'
            ? row.accounting_preview : null;

        setText(article, '[data-adn-supplier]', preview?.supplier_name || list.dataset.unknownSupplierLabel);
        setText(article, '[data-adn-access-key]', row.chave_acesso || row.document_key);
        setText(article, '[data-adn-role]', row.fiscal_role);
        setText(article, '[data-adn-competence]', preview?.competence);
        setText(article, '[data-adn-gross]', preview?.gross_value);
        setText(article, '[data-adn-liquid]', preview?.liquid_value);
        setText(article, '[data-adn-service]', preview?.service_description);

        const importForm = article.querySelector('form[data-adn-json-action="import"]');
        const ignoreForm = article.querySelector('form[data-adn-json-action="ignore"]');
        importForm.action = urlFor(list.dataset.importUrlTemplate, row.id);
        ignoreForm.action = urlFor(list.dataset.ignoreUrlTemplate, row.id);

        if (!preview) {
            article.querySelector('[data-adn-preview-details]').classList.add('hidden');
            article.querySelector('[data-adn-invalid-xml]').classList.remove('hidden');
            setText(article, '[data-adn-invalid-xml]', list.dataset.invalidXmlLabel);
            importForm.classList.add('hidden');
        } else {
            addOptions(documentRef, importForm.querySelector('[name="contact_id"]'), options.vendors);
            addOptions(documentRef, importForm.querySelector('[name="category_id"]'), options.categories);
            addOptions(documentRef, importForm.querySelector('[name="item_id"]'), options.items);
            const issuedAt = String(preview.competence || '');
            if (/^\d{4}-\d{2}-\d{2}$/.test(issuedAt)) {
                importForm.querySelector('[name="issued_at"]').value = issuedAt;
                importForm.querySelector('[name="due_at"]').value = issuedAt;
            }
            if (!options.can_create_vendor) {
                importForm.querySelector('[data-adn-create-vendor]').classList.add('hidden');
                importForm.querySelector('[name="contact_id"] option[value="0"]')?.remove();
            }
        }

        if (!options.can_import) {
            importForm.classList.add('hidden');
        }
        if (!options.can_ignore) {
            ignoreForm.classList.add('hidden');
        }
        bindForm(documentRef, importForm, fetchImpl, navigate, reload);
        bindForm(documentRef, ignoreForm, fetchImpl, navigate, reload);
        return article;
    }

    function displayMessage(documentRef, list, message) {
        const text = documentRef.createElement('p');
        text.className = 'text-sm text-gray-600';
        text.textContent = message;
        list.replaceChildren(text);
    }

    async function load(documentRef, list, template, fetchImpl, navigate, reload) {
        const listUrl = new URL(list.dataset.listUrl, documentRef.baseURI);
        listUrl.searchParams.set('review_status', 'pending');
        listUrl.searchParams.set('limit', '50');
        listUrl.searchParams.set('include_preview', '1');

        try {
            const [rows, options] = await Promise.all([
                getJson(fetchImpl, listUrl.toString()),
                getJson(fetchImpl, list.dataset.optionsUrl),
            ]);
            const entries = eligibleRows(rows);
            if (!options || !Array.isArray(options.vendors)
                || !Array.isArray(options.categories) || !Array.isArray(options.items)) {
                throw new Error('Invalid ADN review choices.');
            }
            if (options.can_query_distribution && typeof options.issuer_cnpj === 'string') {
                const cnpjInput = documentRef.getElementById('adn-cnpj');
                if (cnpjInput && !cnpjInput.value) {
                    cnpjInput.value = options.issuer_cnpj;
                }
            }
            if (!options.can_query_distribution) {
                documentRef.getElementById('adn-distribution-browser')?.classList.add('hidden');
            }
            if (entries.length === 0) {
                displayMessage(documentRef, list, list.dataset.emptyLabel || '');
                return;
            }
            list.replaceChildren(...entries.map((row) =>
                renderReview(documentRef, template, list, row, options, fetchImpl, navigate, reload)));
        } catch (error) {
            displayMessage(documentRef, list, error instanceof Error ? error.message : list.dataset.errorLabel || '');
        }
    }

    function init(documentRef, fetchImpl, navigate, reload) {
        const list = documentRef.getElementById('adn-review-list');
        const template = documentRef.getElementById('adn-review-template');
        if (!list || !template) {
            return false;
        }
        void load(documentRef, list, template, fetchImpl, navigate, reload);
        return true;
    }

    function boot(documentRef, fetchImpl, navigate, reload) {
        if (!documentRef) {
            return;
        }
        const run = () => init(documentRef, fetchImpl, navigate, reload);
        if (documentRef.readyState === 'complete' || documentRef.readyState === 'interactive') {
            run();
        } else {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        }
    }

    return { init, boot, urlFor, eligibleRows, getJson };
});

if (typeof document !== 'undefined' && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined' && globalThis.NfseAdnReviewBrowser) {
    globalThis.NfseAdnReviewBrowser.boot(
        document,
        fetch,
        (url) => { globalThis.location.assign(url); },
        () => { globalThis.location.reload(); },
    );
}
