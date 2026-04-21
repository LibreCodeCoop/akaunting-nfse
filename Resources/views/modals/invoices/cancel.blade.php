{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
@php($cancelReasonOptions = trans('nfse::general.invoices.cancel_reason_options'))

<x-form id="form-email" method="DELETE" :route="[$cancel_route, $invoice->id]">
    <x-form.section>
        <x-slot name="body">
            <input type="hidden" name="redirect_after_cancel" value="{{ $redirect_after_cancel ?? 'nfse_index' }}">

            <x-form.group.select
                name="cancel_reason"
                :label="trans('nfse::general.invoices.cancel_modal_reason')"
                :options="is_array($cancelReasonOptions) ? $cancelReasonOptions : []"
                :placeholder="trans('nfse::general.invoices.cancel_modal_reason_select_placeholder')"
                form-group-class="sm:col-span-6"
            />

            <x-form.group.textarea
                name="cancel_justification"
                :label="trans('nfse::general.invoices.cancel_modal_justification')"
                :placeholder="trans('nfse::general.invoices.cancel_modal_justification_placeholder')"
                rows="4"
                form-group-class="sm:col-span-6"
            />
        </x-slot>
    </x-form.section>
</x-form>
