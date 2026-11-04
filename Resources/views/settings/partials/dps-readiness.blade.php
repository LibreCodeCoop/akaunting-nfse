{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
@can('read-nfse-settings')
    <section
        id="nfse-dps-diagnostics"
        class="rounded border bg-white p-4"
        aria-labelledby="nfse-dps-diagnostics-title"
        data-readiness-url="{{ route('nfse.dps.readiness') }}"
        data-lookup-url="{{ route('nfse.dps.lookup') }}"
        data-ready-label="{{ trans('nfse::general.dps_diagnostic.mechanically_ready') }}"
        data-blocked-label="{{ trans('nfse::general.dps_diagnostic.blocked') }}"
        data-unavailable-label="{{ trans('nfse::general.dps_diagnostic.unavailable') }}"
        data-lookup-label="{{ trans('nfse::general.dps_diagnostic.lookup') }}"
        data-checking-label="{{ trans('nfse::general.dps_diagnostic.checking') }}"
        data-found-label="{{ trans('nfse::general.dps_diagnostic.found') }}"
        data-not-found-label="{{ trans('nfse::general.dps_diagnostic.not_found') }}"
        data-inconclusive-label="{{ trans('nfse::general.dps_diagnostic.inconclusive') }}"
    >
        <h2 id="nfse-dps-diagnostics-title" class="font-semibold">{{ trans('nfse::general.dps_diagnostic.title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ trans('nfse::general.dps_diagnostic.caution') }}</p>
        <p class="mt-3 text-sm" role="status" data-dps-summary>{{ trans('nfse::general.dps_diagnostic.checking') }}</p>
        <button type="button" class="mt-3 rounded border px-3 py-2 text-sm" data-dps-lookup disabled>
            {{ trans('nfse::general.dps_diagnostic.lookup_unavailable') }}
        </button>
        <p class="mt-2 text-sm" role="status" aria-live="polite" data-dps-result></p>
    </section>
    <script src="{{ asset('modules/Nfse/Resources/assets/js/dps-readiness.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/dps-readiness.js'))) }}"></script>
@endcan
