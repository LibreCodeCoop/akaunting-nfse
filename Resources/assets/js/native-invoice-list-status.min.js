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
        classesForStatus,
        decorate,
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
}
