{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
@php
    $missingItems = is_array($preview['missing_items'] ?? null) ? $preview['missing_items'] : [];
    $defaultServiceId = (int) ($preview['default_service_id'] ?? 0);
    $requiresSplit = (bool) ($preview['requires_split'] ?? false);
    $fiscalGroups = is_array($preview['fiscal_groups'] ?? null) ? $preview['fiscal_groups'] : [];
    $suggestedDescription = (string) ($preview['suggested_description'] ?? '');
    $emailDefaults = is_array($preview['email_defaults'] ?? null) ? $preview['email_defaults'] : [];
    $bodyValue = (string) ($emailDefaults['body'] ?? '');
    $sendEmailDefault = (bool) ($emailDefaults['send_email'] ?? false);
    $copyToSelfDefault = (bool) ($emailDefaults['copy_to_self'] ?? false);
    $attachInvoicePdfDefault = (bool) ($emailDefaults['attach_invoice_pdf'] ?? true);
    $attachDanfseDefault = (bool) ($emailDefaults['attach_danfse'] ?? true);
    $attachXmlDefault = (bool) ($emailDefaults['attach_xml'] ?? true);
    $currentSubject = (string) ($emailDefaults['subject'] ?? '');
    $currentBody = (string) ($emailDefaults['body'] ?? '');
    $defaultSubject = (string) ($emailDefaults['default_subject'] ?? '');
    $defaultBody = (string) ($emailDefaults['default_body'] ?? '');
    $defaultSubjectEncoded = rawurlencode($defaultSubject);
    $defaultBodyEncoded = rawurlencode($defaultBody);
    $showRestoreDefault =
        ($defaultSubject !== '' || $defaultBody !== '')
        && ($currentSubject !== $defaultSubject || $currentBody !== $defaultBody);
    $restoreButtonStyle = $showRestoreDefault ? '' : 'display:none;';
@endphp

<x-form id="form-email" :route="[$issue_route, $invoice->id]">
    <x-form.input.hidden name="document_id" :value="$invoice->id" />
    <x-form.input.hidden name="nfse_force_ajax" value="1" />

    <div data-nfse-tabs="true">
        <div data-tabs-swiper>
            <ul id="nfse-tab-nav-list" role="tablist" aria-label="NFS-e" class="grid w-full auto-rows-max {{ $sendEmailDefault ? 'grid-cols-3' : 'grid-cols-2' }}">
                <li id="nfse-tab-nav-issuance"
                    role="tab"
                    tabindex="0"
                    aria-selected="true"
                    aria-controls="nfse-tab-pane-issuance"
                    data-nfse-tab-nav="nfse-tab-pane-issuance"
                    class="relative flex-auto px-4 text-sm text-center pb-2 cursor-pointer transition-all border-b whitespace-nowrap tabs-link active-tabs text-purple border-purple after:absolute after:w-full after:h-0.5 after:left-0 after:right-0 after:bottom-0 after:bg-purple after:rounded-tl-md after:rounded-tr-md"
                >
                    {{ trans('general.general') }}
                </li>

                <li id="nfse-tab-nav-email"
                    role="tab"
                    tabindex="-1"
                    aria-selected="false"
                    aria-controls="nfse-tab-pane-email"
                    data-nfse-tab-nav="nfse-tab-pane-email"
                    class="relative flex-auto px-4 text-sm text-center pb-2 cursor-pointer transition-all border-b whitespace-nowrap tabs-link text-black"
                >
                    {{ trans_choice('general.email', 1) }}
                </li>

                <li id="nfse-tab-nav-attachments"
                    role="tab"
                    tabindex="-1"
                    aria-selected="false"
                    aria-controls="nfse-tab-pane-attachments"
                    data-nfse-tab-nav="nfse-tab-pane-attachments"
                    class="relative flex-auto px-4 text-sm text-center pb-2 cursor-pointer transition-all border-b whitespace-nowrap tabs-link text-black"
                    style="{{ $sendEmailDefault ? '' : 'display:none;' }}"
                >
                    {{ trans_choice('general.attachments', 2) }}
                </li>
            </ul>
        </div>

        <div id="nfse-tab-pane-issuance" role="tabpanel" aria-labelledby="nfse-tab-nav-issuance" aria-hidden="false" data-nfse-tab-pane="true">
                <x-form.section>
                    <x-slot name="body">
                        @if ($requiresSplit)
                            <div class="sm:col-span-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                                <p class="font-medium">{{ trans('nfse::general.invoices.fiscal_groups_preview_title') }}</p>
                                <p class="mt-1">{{ trans('nfse::general.invoices.fiscal_groups_preview_help') }}</p>

                                <div class="mt-3 space-y-2">
                                    @foreach($fiscalGroups as $group)
                                        <label class="block rounded border border-blue-200 bg-white px-3 py-2 {{ ($group['issued'] ?? false) ? 'opacity-70' : 'cursor-pointer' }}" data-nfse-fiscal-group="{{ $group['key'] ?? '' }}">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="flex items-center gap-2 font-medium">
                                                    @if(!($group['issued'] ?? false))
                                                        <input
                                                            type="radio"
                                                            name="nfse_fiscal_group_key"
                                                            value="{{ $group['key'] ?? '' }}"
                                                            required
                                                        >
                                                    @endif
                                                    <span>
                                                        {{ $group['item_lista_servico'] ?? '' }} /
                                                        {{ $group['codigo_tributacao_nacional'] ?? '' }}
                                                        — {{ $group['aliquota'] ?? '' }}%
                                                    </span>
                                                    @php($rtcCategory = (string) ($group['rtc_supply_category'] ?? ''))
                                                    @if($rtcCategory !== '')
                                                        <span class="text-xs">{{ trans('nfse::general.items.rtc_' . $rtcCategory) }}</span>
                                                    @endif
                                                    @if($rtcCategory !== '' && $rtcCategory !== 'ordinary_lc116')
                                                        <span class="text-xs text-red-700">{{ trans('nfse::general.invoices.rtc_supply_category_unsupported', ['category' => trans('nfse::general.items.rtc_' . $rtcCategory)]) }}</span>
                                                    @endif
                                                </span>
                                                <span>
                                                    {{ trans('nfse::general.invoices.fiscal_group_amount', ['amount' => $group['amount'] ?? '0.00']) }}
                                                </span>
                                            </div>
                                            <p class="mt-1 text-xs">
                                                {{ trans_choice(
                                                    'nfse::general.invoices.fiscal_group_items',
                                                    count($group['items'] ?? []),
                                                    ['count' => count($group['items'] ?? [])]
                                                ) }}
                                            </p>
                                            @if($group['issued'] ?? false)
                                                <p class="mt-1 text-xs font-medium text-green-700">
                                                    {{ trans('nfse::general.invoices.fiscal_group_already_issued', ['number' => $group['nfse_number'] ?? '']) }}
                                                </p>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>

                                <p class="mt-3 text-xs">
                                    {{ trans('nfse::general.invoices.fiscal_groups_issue_one_at_a_time') }}
                                </p>
                            </div>
                        @elseif ($missingItems !== [] && $defaultServiceId <= 0)
                            <div class="sm:col-span-6 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                                {{ trans('nfse::general.invoices.emit_modal_missing_items_hint') }}
                            </div>
                        @elseif ($missingItems !== [] && $defaultServiceId > 0)
                            <div class="sm:col-span-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                                {{ trans('nfse::general.invoices.default_service_confirmation_required') }}
                            </div>
                        @endif

                        <x-form.group.textarea
                            name="nfse_discriminacao_custom"
                            label="{{ trans('nfse::general.invoices.emit_modal_description') }}"
                            rows="4"
                            value="{{ $suggestedDescription }}"
                            not-required
                            form-group-class="sm:col-span-6"
                        />

                        <div class="sm:col-span-6 -mt-4 text-xs text-gray-500">
                            {{ trans('nfse::general.invoices.emit_modal_description_help') }}
                        </div>

                        <div class="sm:col-span-6 flex items-center gap-3">
                            @include('nfse::modals.invoices.partials.switch', [
                                'id' => 'nfse_save_default_description_toggle',
                                'name' => 'nfse_save_default_description',
                                'label' => trans('nfse::general.invoices.emit_modal_description_save_default'),
                            ])
                            <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_description_save_default') }}</span>
                        </div>

                        <div id="nfse-description-default-hint" class="sm:col-span-6 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                            {{ trans('nfse::general.invoices.emit_modal_description_save_default_hint') }}
                        </div>
                    </x-slot>
                </x-form.section>
        </div>

        <div id="nfse-tab-pane-email" role="tabpanel" aria-labelledby="nfse-tab-nav-email" aria-hidden="true" data-nfse-tab-pane="true" style="display:none;">
                <x-form.section>
                    <x-slot name="body">
                        <div class="sm:col-span-6 flex items-center gap-3">
                            @include('nfse::modals.invoices.partials.switch', [
                                'id' => 'nfse_send_email_toggle',
                                'name' => 'nfse_send_email',
                                'label' => trans('nfse::general.invoices.emit_modal_send_email'),
                                'checked' => $sendEmailDefault,
                            ])
                            <div>
                                <p class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_send_email') }}</p>
                                <p class="text-xs text-gray-500">{{ trans('nfse::general.invoices.emit_modal_send_email_hint') }}</p>
                            </div>
                        </div>

                        <div id="nfse-email-fields"
                            class="sm:col-span-6 space-y-3 {{ $sendEmailDefault ? '' : 'hidden' }}"
                        >
                            <x-form.group.contact
                                name="nfse_email_to"
                                label="{{ trans('nfse::general.invoices.emit_modal_email_to') }}"
                                :type="$invoice->contact->type"
                                :options="$contacts"
                                :option_field="[
                                    'key' => 'email',
                                    'value' => 'email'
                                ]"
                                :selected="!empty($emailDefaults['recipient']) ? [(string) $emailDefaults['recipient']] : []"
                                without-remote
                                without-add-new
                                multiple
                                form-group-class="sm:col-span-6"
                            />

                            <x-form.group.text
                                name="nfse_email_subject"
                                label="{{ trans('nfse::general.invoices.emit_modal_email_subject') }}"
                                value="{{ $currentSubject }}"
                                form-group-class="sm:col-span-6"
                            />

                            <x-form.group.editor
                                name="nfse_email_body"
                                label="{{ trans('nfse::general.invoices.emit_modal_email_body') }}"
                                :value="$bodyValue"
                                rows="3"
                                data-toggle="quill"
                                form-group-class="sm:col-span-6 mb-0"
                            />

                            <div class="sm:col-span-6 text-xs text-gray-500">
                                {{ trans('nfse::general.invoices.emit_modal_email_body_help') }}
                            </div>

                            @if ($defaultSubject !== '' || $defaultBody !== '')
                                <div class="sm:col-span-6 text-right">
                                    <button type="button"
                                        data-nfse-restore-default="true"
                                        class="text-xs text-purple hover:underline"
                                        style="{{ $restoreButtonStyle }}"
                                        data-nfse-default-subject="{{ $defaultSubjectEncoded }}"
                                        data-nfse-default-body="{{ $defaultBodyEncoded }}"
                                    >
                                        {{ trans('nfse::general.invoices.emit_modal_email_restore_default') }}
                                    </button>
                                </div>
                            @endif

                            <div class="sm:col-span-6 rounded-md bg-gray-100 p-3 text-xs text-gray-600">
                                {!! trans('settings.email.templates.tags', ['tag_list' => implode(', ', $notification->getTags())]) !!}
                            </div>

                            <div class="sm:col-span-6 flex items-center gap-3">
                                @include('nfse::modals.invoices.partials.switch', [
                                    'id' => 'nfse_email_save_default_toggle',
                                    'name' => 'nfse_email_save_default',
                                    'label' => trans('nfse::general.invoices.emit_modal_email_save_default'),
                                ])
                                <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_save_default') }}</span>
                            </div>

                            <div class="sm:col-span-6 flex items-center gap-3 -mb-8">
                                @include('nfse::modals.invoices.partials.switch', [
                                    'id' => 'nfse_email_copy_to_self_toggle',
                                    'name' => 'nfse_email_copy_to_self',
                                    'label' => trans('general.email_send_me', ['email' => user()->email]),
                                    'checked' => $copyToSelfDefault,
                                ])
                                <span class="text-sm font-medium text-gray-700">{{ trans('general.email_send_me', ['email' => user()->email]) }}</span>
                            </div>
                        </div>
                    </x-slot>
                </x-form.section>
        </div>

        <div id="nfse-tab-pane-attachments" role="tabpanel" aria-labelledby="nfse-tab-nav-attachments" aria-hidden="true" data-nfse-tab-pane="true" style="display:none;">
                <x-form.section>
                    <x-slot name="body">
                        <div class="sm:col-span-6 space-y-3">
                            <div class="flex items-center gap-3">
                                @include('nfse::modals.invoices.partials.switch', [
                                    'id' => 'nfse_email_attach_invoice_pdf_toggle',
                                    'name' => 'nfse_email_attach_invoice_pdf',
                                    'label' => trans('nfse::general.invoices.emit_modal_email_attach_invoice_pdf'),
                                    'checked' => $attachInvoicePdfDefault,
                                ])
                                <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_attach_invoice_pdf') }}</span>
                            </div>

                            <div class="flex items-center gap-3">
                                @include('nfse::modals.invoices.partials.switch', [
                                    'id' => 'nfse_email_attach_danfse_toggle',
                                    'name' => 'nfse_email_attach_danfse',
                                    'label' => trans('nfse::general.invoices.emit_modal_email_attach_danfse'),
                                    'checked' => $attachDanfseDefault,
                                ])
                                <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_attach_danfse') }}</span>
                            </div>

                            <div class="flex items-center gap-3">
                                @include('nfse::modals.invoices.partials.switch', [
                                    'id' => 'nfse_email_attach_xml_toggle',
                                    'name' => 'nfse_email_attach_xml',
                                    'label' => trans('nfse::general.invoices.emit_modal_email_attach_xml'),
                                    'checked' => $attachXmlDefault,
                                ])
                                <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_attach_xml') }}</span>
                            </div>
                        </div>
                    </x-slot>
                </x-form.section>
        </div>
    </div>

    <div
        v-if="form.response && form.response.error"
        class="mx-5 mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        role="alert"
        tabindex="-1"
        data-nfse-error-summary="true"
    >
        @{{ (form.response && form.response.message) ? form.response.message : @json((string) trans('nfse::general.nfse_emit_failed')) }}
    </div>

</x-form>
