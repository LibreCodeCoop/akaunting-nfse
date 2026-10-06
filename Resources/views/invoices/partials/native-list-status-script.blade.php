{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<script data-nfse-native-list-map="true">
    window.nfseInvoiceFiscalStatuses = @json($nfseInvoiceFiscalStatuses ?? []);
</script>
<script
    src="{{ asset('modules/Nfse/Resources/assets/js/native-invoice-list-status.min.js?v=' . module_version('nfse')) }}"
    data-nfse-native-list-module="true"
></script>
