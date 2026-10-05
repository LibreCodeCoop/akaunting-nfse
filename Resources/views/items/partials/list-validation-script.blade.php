{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<script>
document.addEventListener('DOMContentLoaded', () => {
    const statuses = @json($nfseItemListValidation);

    Object.entries(statuses).forEach(([itemId, meta]) => {
        const editLink = document.querySelector(
            '#index-line-actions-edit-item-' + CSS.escape(String(itemId))
        );

        const row = editLink?.closest('tr');

        if (!row) {
            return;
        }

        const target = row.querySelector('td:nth-child(2)');

        if (!target || target.querySelector('[data-nfse-fiscal-validation]')) {
            return;
        }

        const badge = document.createElement('a');
        badge.href = String(meta.url || '#');
        badge.dataset.nfseFiscalValidation = String(meta.status || 'unverifiable');
        badge.className = 'mt-1 inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700';
        badge.textContent = String(meta.label || 'NFS-e');
        badge.setAttribute('aria-label', 'NFS-e: ' + badge.textContent);

        target.appendChild(badge);
    });
});
</script>
