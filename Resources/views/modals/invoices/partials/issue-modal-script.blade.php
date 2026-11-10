{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
@once
    <link rel="stylesheet"
        href="{{ asset('modules/Nfse/Resources/assets/vendor/ckeditor5/nfse-email-ckeditor.css?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/vendor/ckeditor5/nfse-email-ckeditor.css'))) }}"
        data-nfse-ckeditor-styles="true"
    >
    <script
        src="{{ asset('modules/Nfse/Resources/assets/vendor/ckeditor5/nfse-email-ckeditor.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/vendor/ckeditor5/nfse-email-ckeditor.js'))) }}"
        data-nfse-ckeditor-bundle="true"
    ></script>
    <link rel="stylesheet"
        href="{{ asset('modules/Nfse/Resources/assets/css/email-composer.css?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/css/email-composer.css'))) }}"
        data-nfse-email-composer-style="true"
    >
    <script
        src="{{ asset('modules/Nfse/Resources/assets/js/email-recipients.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/email-recipients.js'))) }}"
        data-nfse-recipient-module="true"
    ></script>
    <script
        src="{{ asset('modules/Nfse/Resources/assets/js/issue-modal.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/issue-modal.js'))) }}"
        data-nfse-issue-modal-module="true"
    ></script>
@endonce
