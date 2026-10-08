{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<script>
document.addEventListener('DOMContentLoaded', () => {
    const main = document.querySelector('main');
    if (!main || document.getElementById('nfse-fiscal-status-filter')) return;
    const form = document.createElement('form');
    form.id = 'nfse-fiscal-status-filter';
    form.method = 'GET';
    form.action = @json(route('invoices.index'));
    form.className = 'mb-4 flex gap-2 items-center';
    const label = document.createElement('label');
    label.htmlFor = 'nfse-status-option';
    label.textContent = @json(trans('nfse::general.ledger.filter_fiscal_status'));
    const select = document.createElement('select');
    select.id = 'nfse-status-option';
    select.name = 'nfse_status';
    select.className = 'border rounded px-3 py-2';
    const options = @json($fiscalStatuses);
    const selected = @json($fiscalFilter);
    Object.entries(options).forEach(([value, text]) => {
        const option = new Option(text, value);
        option.selected = value === selected;
        select.add(option);
    });
    const url = new URL(window.location.href);
    url.searchParams.delete('nfse_status');
    url.searchParams.delete('page');
    for (const [key, value] of url.searchParams) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = key;
        hidden.value = value;
        form.append(hidden);
    }
    const button = document.createElement('button');
    button.type = 'submit';
    button.textContent = @json(trans('nfse::general.ledger.filter'));
    button.className = 'rounded bg-indigo-600 text-white px-3 py-2';
    form.append(label, select, button);
    main.prepend(form);
});
</script>
