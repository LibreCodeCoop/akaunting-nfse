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
        data-adjustment-success-label="{{ trans('nfse::general.dps_diagnostic.adjustment_success') }}"
        data-adjustment-failed-label="{{ trans('nfse::general.dps_diagnostic.adjustment_failed') }}"
    >
        <h2 id="nfse-dps-diagnostics-title" class="font-semibold">{{ trans('nfse::general.dps_diagnostic.title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ trans('nfse::general.dps_diagnostic.caution') }}</p>
        <p class="mt-3 text-sm" role="status" data-dps-summary>{{ trans('nfse::general.dps_diagnostic.checking') }}</p>
        <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3" data-dps-sequence hidden>
            <div>
                <dt class="text-xs text-gray-600">{{ trans('nfse::general.dps_diagnostic.series_label') }}</dt>
                <dd class="font-semibold" data-dps-series></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-600">{{ trans('nfse::general.dps_diagnostic.last_number_label') }}</dt>
                <dd class="font-semibold" data-dps-last></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-600">{{ trans('nfse::general.dps_diagnostic.next_number_label') }}</dt>
                <dd class="font-semibold" data-dps-next></dd>
            </div>
        </dl>
        <button type="button" class="mt-3 rounded border px-3 py-2 text-sm" data-dps-lookup disabled>
            {{ trans('nfse::general.dps_diagnostic.lookup_unavailable') }}
        </button>
        <p class="mt-2 text-sm" role="status" aria-live="polite" data-dps-result></p>

        @can('update-nfse-settings')
            <form class="mt-4 space-y-3 border-t pt-4" data-dps-advance-form
                data-write-url="{{ route('nfse.dps.advance') }}"
                data-csrf-token="{{ csrf_token() }}" hidden>
                <h3 class="font-semibold">{{ trans('nfse::general.dps_diagnostic.advance_title') }}</h3>
                <p class="text-sm text-gray-600">{{ trans('nfse::general.dps_diagnostic.advance_caution') }}</p>
                <label class="block text-sm">
                    {{ trans('nfse::general.dps_diagnostic.next_number_input') }}
                    <input type="text" inputmode="numeric" pattern="[1-9][0-9]{0,14}" maxlength="15"
                        class="mt-1 block w-full rounded border p-2" data-dps-advance-next required>
                </label>
                <label class="block text-sm">
                    {{ trans('nfse::general.dps_diagnostic.reason_label') }}
                    <textarea class="mt-1 block w-full rounded border p-2" minlength="12" maxlength="500"
                        rows="2" data-dps-advance-reason required></textarea>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" class="mt-1" data-dps-advance-confirm required>
                    <span>{{ trans('nfse::general.dps_diagnostic.confirm_label') }}</span>
                </label>
                <button class="rounded bg-green-700 px-4 py-2 text-sm text-white" type="submit"
                    data-dps-advance-button>{{ trans('nfse::general.dps_diagnostic.advance_button') }}</button>
                <p class="text-sm" role="status" aria-live="polite" data-dps-advance-status></p>
            </form>
        @endcan
    </section>
    <script src="{{ asset('modules/Nfse/Resources/assets/js/dps-readiness.js?v=' . module_version('nfse') . '-' . filemtime(base_path('modules/Nfse/Resources/assets/js/dps-readiness.js'))) }}"></script>
@endcan
