{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<button
    type="button"
    class="hidden"
    data-nfse-native-modal-trigger="true"
    aria-hidden="true"
    tabindex="-1"
    @click="onSendEmail('{{ route('nfse.modals.invoices.emails.create', $invoice->id) }}')"
></button>
