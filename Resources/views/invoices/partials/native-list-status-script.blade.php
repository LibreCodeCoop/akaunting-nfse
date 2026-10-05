{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<script data-nfse-native-list-map="true">
(function () {
    const statuses = @json($nfseInvoiceFiscalStatuses ?? []);

    const classes = {
        emitted: ['bg-green-100', 'text-green-700'],
        cancelled: ['bg-gray-100', 'text-gray-700'],
        substituted: ['bg-gray-100', 'text-gray-700'],
        processing: ['bg-blue-100', 'text-blue-700'],
        pending: ['bg-yellow-100', 'text-yellow-700'],
    };

    Object.entries(statuses).forEach(([invoiceId, fiscal]) => {
        const checkbox = document.querySelector('[data-bulk-action="' + invoiceId + '"]');

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

        const link = document.createElement('a');
        link.href = fiscal.url;
        link.dataset.nfseListStatus = invoiceId;
        link.classList.add(
            'ml-1',
            'inline-flex',
            'rounded-xl',
            'px-2.5',
            'py-1',
            'text-xs',
            'font-medium',
            'whitespace-nowrap',
            ...(classes[fiscal.status] || classes.pending),
        );
        link.textContent = 'NFS-e: ' + fiscal.label;
        link.setAttribute('aria-label', 'NFS-e: ' + fiscal.label);

        target.appendChild(link);
    });
})();
</script>
