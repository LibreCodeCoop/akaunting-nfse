// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseNativeInvoiceListStatus = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const classes = {
        emitted: ['bg-green-100', 'text-green-700'],
        cancelled: ['bg-gray-100', 'text-gray-700'],
        substituted: ['bg-gray-100', 'text-gray-700'],
        processing: ['bg-blue-100', 'text-blue-700'],
        pending: ['bg-yellow-100', 'text-yellow-700'],
    };

    function classesForStatus(status) {
        return classes[status] || classes.pending;
    }

    function badgeDescriptor(invoiceId, fiscal) {
        const label = String(fiscal && fiscal.label ? fiscal.label : '');
        const status = String(fiscal && fiscal.status ? fiscal.status : 'pending');

        return {
            href: String(fiscal && fiscal.url ? fiscal.url : '#'),
            invoiceId: String(invoiceId),
            label: 'NFS-e: ' + label,
            classes: classesForStatus(status),
        };
    }

    function selectedInvoiceIds(rootNode) {
        if (!rootNode || typeof rootNode.querySelectorAll !== 'function') {
            return [];
        }

        const values = Array.from(rootNode.querySelectorAll('[data-bulk-action]:checked'))
            .map((checkbox) => Number.parseInt(String(checkbox.value || checkbox.dataset?.bulkAction || ''), 10))
            .filter((invoiceId) => Number.isInteger(invoiceId) && invoiceId > 0);

        return Array.from(new Set(values));
    }

    function bindBulkSelectionSync(rootNode, sync) {
        if (!rootNode || typeof rootNode.addEventListener !== 'function') {
            return false;
        }

        rootNode.__nfseBulkDispatchSync = sync;

        if (rootNode.__nfseBulkDispatchEventsBound) {
            return true;
        }

        const delegatedSync = (event) => {
            const target = event ? event.target : null;

            if (!target || typeof target.matches !== 'function' || !target.matches('[data-bulk-action]')) {
                return;
            }

            const currentSync = rootNode.__nfseBulkDispatchSync;

            if (typeof currentSync === 'function') {
                currentSync();
            }
        };

        // Akaunting's Vue handlers may stop bubbling after updating the native
        // selection state. Capture the event first so replacement checkboxes and
        // framework-owned handlers cannot bypass the NFS-e dispatch synchronizer.
        rootNode.addEventListener('change', delegatedSync, true);
        rootNode.addEventListener('input', delegatedSync, true);
        rootNode.__nfseBulkDispatchEventsBound = true;

        return true;
    }

    function mountBulkDispatch(rootNode, config) {
        if (!rootNode || typeof rootNode.querySelectorAll !== 'function' || typeof rootNode.createElement !== 'function') {
            return null;
        }

        if (!config || !config.url || rootNode.querySelector('[data-nfse-bulk-dispatch-form]')) {
            return null;
        }

        const checkboxes = Array.from(rootNode.querySelectorAll('[data-bulk-action]'));

        if (checkboxes.length === 0) {
            return null;
        }

        const firstTable = typeof checkboxes[0].closest === 'function' ? checkboxes[0].closest('table') : null;

        if (!firstTable || !firstTable.parentNode || typeof firstTable.parentNode.insertBefore !== 'function') {
            return null;
        }

        const form = rootNode.createElement('form');
        form.method = 'post';
        form.action = String(config.url);
        form.dataset.nfseBulkDispatchForm = 'true';
        form.classList.add('mb-3', 'flex', 'justify-end');

        const token = rootNode.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = String(config.csrfToken || '');
        form.appendChild(token);

        const button = rootNode.createElement('button');
        button.type = 'submit';
        button.textContent = String(config.label || 'Issue selected NFS-e');
        button.classList.add('rounded', 'bg-green-600', 'px-4', 'py-2', 'text-sm', 'font-medium', 'text-white');
        form.appendChild(button);

        const sync = () => {
            button.disabled = selectedInvoiceIds(rootNode).length === 0;
        };

        form.addEventListener('submit', (event) => {
            form.querySelectorAll('[data-nfse-bulk-invoice]').forEach((input) => input.remove());
            const selected = selectedInvoiceIds(rootNode);

            if (selected.length === 0) {
                event.preventDefault();
                sync();
                return;
            }

            selected.forEach((invoiceId) => {
                const input = rootNode.createElement('input');
                input.type = 'hidden';
                input.name = 'invoice_ids[]';
                input.value = String(invoiceId);
                input.dataset.nfseBulkInvoice = 'true';
                form.appendChild(input);
            });
        });

        if (!bindBulkSelectionSync(rootNode, sync)) {
            checkboxes.forEach((checkbox) => {
                if (typeof checkbox.addEventListener === 'function') {
                    checkbox.addEventListener('change', sync);
                    checkbox.addEventListener('input', sync);
                }
            });
        }

        sync();
        firstTable.parentNode.insertBefore(form, firstTable);

        return form;
    }

    function decorate(rootNode, statuses) {
        if (!rootNode || typeof rootNode.querySelector !== 'function') {
            return 0;
        }

        let decorated = 0;

        Object.entries(statuses || {}).forEach(([invoiceId, fiscal]) => {
            const checkbox = rootNode.querySelector('[data-bulk-action="' + invoiceId + '"]');

            if (!checkbox) {
                return;
            }

            const row = checkbox.closest('tr');

            if (!row || row.querySelector('[data-nfse-list-status="' + invoiceId + '"]')) {
                return;
            }

            const accountingStatus = row.querySelector('span.rounded-xl.whitespace-nowrap');
            const target = accountingStatus ? accountingStatus.parentElement : null;

            if (!target) {
                return;
            }

            const descriptor = badgeDescriptor(invoiceId, fiscal);
            const link = rootNode.createElement('a');

            link.href = descriptor.href;
            link.dataset.nfseListStatus = descriptor.invoiceId;
            link.classList.add(
                'ml-1',
                'inline-flex',
                'rounded-xl',
                'px-2.5',
                'py-1',
                'text-xs',
                'font-medium',
                'whitespace-nowrap',
                ...descriptor.classes,
            );
            link.textContent = descriptor.label;
            link.setAttribute('aria-label', descriptor.label);

            target.appendChild(link);
            decorated += 1;
        });

        return decorated;
    }

    return {
        badgeDescriptor,
        bindBulkSelectionSync,
        classesForStatus,
        decorate,
        mountBulkDispatch,
        selectedInvoiceIds,
    };
});

if (
    typeof document !== 'undefined'
    && typeof globalThis !== 'undefined'
    && globalThis.NfseNativeInvoiceListStatus
) {
    globalThis.NfseNativeInvoiceListStatus.decorate(
        document,
        globalThis.nfseInvoiceFiscalStatuses || {},
    );
    globalThis.NfseNativeInvoiceListStatus.mountBulkDispatch(
        document,
        globalThis.nfseBulkDispatchConfig || {},
    );
}
