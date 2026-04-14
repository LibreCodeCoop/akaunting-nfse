{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.invoices.details_title') }}</x-slot>

    <x-slot name="content">
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if(session('info'))
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4">
                {{ session('info') }}
            </div>
        @endif

        @if($errors->has('cancel_reason') || $errors->has('cancel_justification'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                @error('cancel_reason')
                    <p>{{ $message }}</p>
                @enderror
                @error('cancel_justification')
                    <p>{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white rounded border p-4">
                <h2 class="font-semibold mb-3">{{ trans('nfse::general.invoices.receipt_data') }}</h2>
                <dl class="grid grid-cols-1 gap-2 text-sm">
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.nfse_number') }}</dt><dd>{{ $receipt->nfse_number ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.access_key') }}</dt><dd class="break-all">{{ $receipt->chave_acesso ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.verification_code') }}</dt><dd>{{ $receipt->codigo_verificacao ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.issue_date') }}</dt><dd>{{ $receipt->data_emissao ? $receipt->data_emissao->format('d/m/Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('general.status') }}</dt><dd>{{ $receipt->status }}</dd></div>
                </dl>
            </div>

            <div class="bg-white rounded border p-4">
                <h2 class="font-semibold mb-3">{{ trans('nfse::general.invoices.invoice_data') }}</h2>
                <dl class="grid grid-cols-1 gap-2 text-sm">
                    <div><dt class="text-gray-500">{{ trans('general.invoice') }}</dt><dd>{{ $invoice->number ?? ('#' . $invoice->id) }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('nfse::general.invoices.customer') }}</dt><dd>{{ $invoice->contact?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ trans('general.amount') }}</dt><dd>{{ $invoice->amount ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('nfse.invoices.index') }}" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 text-sm">
                {{ trans('nfse::general.invoices.back_to_list') }}
            </a>

            @if(($receipt->status ?? '') !== 'cancelled')
                <button
                    type="button"
                    class="inline-flex items-center px-3 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm"
                    data-cancel-trigger="true"
                    data-cancel-action="{{ route('nfse.invoices.cancel', $invoice) }}"
                >
                    {{ trans('nfse::general.invoices.cancel') }}
                </button>
            @else
                <form action="{{ route('nfse.invoices.reemit', $invoice) }}" method="POST" data-reemit-form="true">
                    @csrf
                        <input type="hidden" name="nfse_discriminacao_custom" id="reemit-discriminacao-input" value="{{ $suggestedDiscriminacao }}">
                        <input type="hidden" name="nfse_send_email" value="0" id="reemit-email-send-hidden">
                        <input type="hidden" name="nfse_email_to" value="" id="reemit-email-to-hidden">
                        <input type="hidden" name="nfse_email_subject" value="" id="reemit-email-subject-hidden">
                        <input type="hidden" name="nfse_email_body" id="reemit-email-body-hidden" value="">
                        <input type="hidden" name="nfse_email_attach_danfse" value="1" id="reemit-email-attach-danfse-hidden">
                        <input type="hidden" name="nfse_email_attach_xml" value="1" id="reemit-email-attach-xml-hidden">
                        <input type="hidden" name="nfse_email_save_default" value="0" id="reemit-email-save-default-hidden">
                    <button
                        type="button"
                        class="inline-flex items-center px-3 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm"
                        data-reemit-trigger="true"
                    >
                        {{ trans('nfse::general.invoices.reemit') }}
                    </button>
                </form>
            @endif
        </div>

        <div
            id="nfse-cancel-modal"
            class="fixed inset-0 z-[100] hidden"
            data-old-action="{{ old('cancel_invoice_action', '') }}"
            aria-hidden="true"
        >
            <div class="absolute inset-0 bg-slate-500/55 backdrop-blur-[1px] backdrop-brightness-75" data-cancel-close="true"></div>

            <div class="relative flex min-h-full items-center justify-center overflow-y-auto p-4">
                <div class="w-full max-w-2xl rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b px-5 py-4">
                        <h3 class="text-lg font-semibold text-gray-800">{{ trans('nfse::general.invoices.cancel_modal_title') }}</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-cancel-close="true">{{ trans('nfse::general.invoices.cancel_modal_close') }}</button>
                    </div>

                    <form id="nfse-cancel-form" method="POST" action="" class="p-5 space-y-4">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="cancel_invoice_action" id="cancel_invoice_action" value="{{ old('cancel_invoice_action', '') }}">
                        @php($cancelReasonOptions = trans('nfse::general.invoices.cancel_reason_options'))

                        <div>
                            <label for="cancel_reason" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.invoices.cancel_modal_reason') }}</label>
                            <select
                                id="cancel_reason"
                                name="cancel_reason"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                required
                            >
                                <option value="">{{ trans('nfse::general.invoices.cancel_modal_reason_select_placeholder') }}</option>
                                @if(is_array($cancelReasonOptions))
                                    @foreach($cancelReasonOptions as $reasonOption)
                                        <option value="{{ $reasonOption }}" @selected(old('cancel_reason', '') === $reasonOption)>{{ $reasonOption }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>

                        <div>
                            <label for="cancel_justification" class="block text-sm font-medium text-gray-700 mb-1">{{ trans('nfse::general.invoices.cancel_modal_justification') }}</label>
                            <textarea
                                id="cancel_justification"
                                name="cancel_justification"
                                rows="4"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                placeholder="{{ trans('nfse::general.invoices.cancel_modal_justification_placeholder') }}"
                                maxlength="1000"
                                required
                            >{{ old('cancel_justification', '') }}</textarea>
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t pt-4">
                            <button type="button" class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm hover:bg-gray-200" data-cancel-close="true">
                                {{ trans('nfse::general.invoices.cancel_modal_close') }}
                            </button>
                            <button id="cancel-submit-button" type="submit" class="inline-flex items-center rounded px-3 py-2 text-sm font-medium transition-colors duration-150 bg-gray-300 text-gray-500 cursor-not-allowed opacity-70" disabled aria-disabled="true" aria-busy="false">
                                <svg id="cancel-submit-spinner" class="mr-2 hidden h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                <span id="cancel-submit-label">{{ trans('nfse::general.invoices.cancel_modal_submit') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="nfse-reemit-modal" class="fixed inset-0 z-[100] hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-slate-500/55 backdrop-blur-[1px] backdrop-brightness-75" data-reemit-close="true"></div>

            <div class="relative flex min-h-full items-center justify-center overflow-y-auto p-4">
                <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                    <div class="border-b px-5 py-4">
                        <h3 class="text-lg font-semibold text-gray-800">{{ trans('nfse::general.invoices.reemit') }}</h3>
                    </div>

                    <div class="px-5 py-4 text-sm text-gray-700">
                        {{ trans('nfse::general.invoices.reemit_confirm') }}
                    </div>

                        <div class="px-5 pb-4">
                            <label for="reemit-description-textarea" class="mb-1 block text-sm font-medium text-gray-700">
                                {{ trans('nfse::general.invoices.reemit_modal_description') }}
                            </label>
                            <textarea
                                id="reemit-description-textarea"
                                rows="4"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                placeholder="{{ trans('nfse::general.invoices.emit_modal_description_placeholder') }}"
                            >{{ $suggestedDiscriminacao }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ trans('nfse::general.invoices.reemit_modal_description_help') }}</p>
                        </div>

                        <div class="px-5 pb-4 space-y-3 border-t pt-4">
                            <div class="flex items-center gap-3">
                                <label for="reemit-send-email-checkbox" class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center" aria-label="{{ trans('nfse::general.invoices.emit_modal_send_email') }}">
                                    <input id="reemit-send-email-checkbox" type="checkbox" class="sr-only" @checked((bool) ($emailDefaults['send_email'] ?? false))>
                                    <div data-toggle="track" class="block h-7 w-12 rounded-full transition-colors duration-200 {{ (bool) ($emailDefaults['send_email'] ?? false) ? 'bg-green' : 'bg-green-200' }}"></div>
                                    <div data-toggle="thumb" class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 {{ (bool) ($emailDefaults['send_email'] ?? false) ? 'translate-x-5' : '' }}"></div>
                                </label>
                                <div>
                                    <p class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_send_email') }}</p>
                                    <p class="text-xs text-gray-500">{{ trans('nfse::general.invoices.emit_modal_send_email_hint') }}</p>
                                </div>
                            </div>

                            <div id="reemit-email-fields" class="space-y-3 {{ (bool) ($emailDefaults['send_email'] ?? false) ? '' : 'hidden' }}">
                            <div>
                                <label for="reemit-email-to-input" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_to') }}</label>
                                <input id="reemit-email-to-input" type="email" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" value="{{ $emailDefaults['recipient'] ?? '' }}">
                            </div>

                            <div>
                                <label for="reemit-email-subject-input" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_subject') }}</label>
                                <input id="reemit-email-subject-input" type="text" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" value="{{ $emailDefaults['subject'] ?? '' }}">
                            </div>

                            <div>
                                <label for="reemit-email-body-input" class="mb-1 block text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_body') }}</label>
                                <textarea id="reemit-email-body-input" rows="4" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">{{ $emailDefaults['body'] ?? '' }}</textarea>
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <label for="reemit-attach-danfse-checkbox" class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center">
                                        <input id="reemit-attach-danfse-checkbox" type="checkbox" class="sr-only" checked>
                                        <div data-toggle="track" class="block h-7 w-12 rounded-full transition-colors duration-200 bg-green"></div>
                                        <div data-toggle="thumb" class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 translate-x-5"></div>
                                    </label>
                                    <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_attach_danfse') }}</span>
                                </div>

                                <div class="flex items-center gap-3">
                                    <label for="reemit-attach-xml-checkbox" class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center">
                                        <input id="reemit-attach-xml-checkbox" type="checkbox" class="sr-only" checked>
                                        <div data-toggle="track" class="block h-7 w-12 rounded-full transition-colors duration-200 bg-green"></div>
                                        <div data-toggle="thumb" class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 translate-x-5"></div>
                                    </label>
                                    <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_attach_xml') }}</span>
                                </div>

                                <div class="flex items-center gap-3">
                                    <label for="reemit-save-default-checkbox" class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center">
                                        <input id="reemit-save-default-checkbox" type="checkbox" class="sr-only">
                                        <div data-toggle="track" class="block h-7 w-12 rounded-full transition-colors duration-200 bg-green-200"></div>
                                        <div data-toggle="thumb" class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200"></div>
                                    </label>
                                    <span class="text-sm font-medium text-gray-700">{{ trans('nfse::general.invoices.emit_modal_email_save_default') }}</span>
                                </div>
                            </div>
                            </div>
                        </div>

                    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
                        <button
                            type="button"
                            class="inline-flex items-center rounded bg-gray-100 px-3 py-2 text-sm hover:bg-gray-200"
                            data-reemit-close="true"
                        >
                            {{ trans('nfse::general.invoices.cancel_modal_close') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center rounded bg-green-600 px-3 py-2 text-sm text-white hover:bg-green-700"
                            id="reemit-confirm-button"
                        >
                            {{ trans('nfse::general.invoices.reemit') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const modal = document.getElementById('nfse-cancel-modal');
                const form = document.getElementById('nfse-cancel-form');
                const reasonSelect = document.getElementById('cancel_reason');
                const justificationInput = document.getElementById('cancel_justification');
                const submitButton = document.getElementById('cancel-submit-button');
                const submitSpinner = document.getElementById('cancel-submit-spinner');
                const submitLabel = document.getElementById('cancel-submit-label');
                const actionInput = document.getElementById('cancel_invoice_action');
                const submitDefaultLabel = @json((string) trans('nfse::general.invoices.cancel_modal_submit'));
                const submitLoadingLabel = @json((string) trans('nfse::general.invoices.cancel_modal_submitting'));
                const reemitModal = document.getElementById('nfse-reemit-modal');
                const reemitForm = document.querySelector('form[data-reemit-form="true"]');
                const reemitTrigger = document.querySelector('[data-reemit-trigger="true"]');
                const reemitConfirmButton = document.getElementById('reemit-confirm-button');
                let isSubmitting = false;

                if (!modal || !form || !reasonSelect || !justificationInput || !submitButton || !submitSpinner || !submitLabel || !actionInput) {
                    return;
                }

                const setSubmittingState = (submitting) => {
                    isSubmitting = submitting;

                    if (submitting) {
                        submitButton.disabled = true;
                        submitButton.setAttribute('aria-disabled', 'true');
                        submitButton.setAttribute('aria-busy', 'true');
                        submitButton.classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                        submitButton.classList.add('bg-gray-400', 'text-white', 'cursor-not-allowed', 'opacity-100');
                        submitSpinner.classList.remove('hidden');
                        submitLabel.textContent = submitLoadingLabel;

                        return;
                    }

                    submitButton.setAttribute('aria-busy', 'false');
                    submitSpinner.classList.add('hidden');
                    submitLabel.textContent = submitDefaultLabel;
                    updateSubmitState();
                };

                const updateSubmitState = () => {
                    if (isSubmitting) {
                        return;
                    }

                    const enabled = reasonSelect.value.trim() !== '' && justificationInput.value.trim() !== '';
                    submitButton.disabled = !enabled;
                    submitButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');

                    if (enabled) {
                        submitButton.classList.remove('bg-gray-300', 'bg-gray-400', 'text-gray-500', 'cursor-not-allowed', 'opacity-70');
                        submitButton.classList.add('bg-red-600', 'text-white', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');

                        return;
                    }

                    submitButton.classList.remove('bg-red-600', 'text-white', 'hover:bg-red-700', 'cursor-pointer', 'opacity-100');
                    submitButton.classList.add('bg-gray-300', 'text-gray-500', 'cursor-not-allowed', 'opacity-70');
                };

                const openModal = (actionUrl) => {
                    if (!actionUrl) {
                        return;
                    }

                    form.action = actionUrl;
                    actionInput.value = actionUrl;
                    modal.classList.remove('hidden');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('overflow-hidden');
                    updateSubmitState();
                    reasonSelect.focus();
                };

                const closeModal = () => {
                    if (isSubmitting) {
                        return;
                    }

                    modal.classList.add('hidden');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('overflow-hidden');
                };

                document.querySelectorAll('[data-cancel-trigger="true"]').forEach((button) => {
                    button.addEventListener('click', () => {
                        openModal(button.getAttribute('data-cancel-action'));
                    });
                });

                modal.querySelectorAll('[data-cancel-close="true"]').forEach((button) => {
                    button.addEventListener('click', closeModal);
                });

                reasonSelect.addEventListener('change', updateSubmitState);
                justificationInput.addEventListener('input', updateSubmitState);
                form.addEventListener('submit', () => {
                    if (isSubmitting) {
                        return;
                    }

                    setSubmittingState(true);
                });

                if (modal.getAttribute('data-old-action')) {
                    openModal(modal.getAttribute('data-old-action'));
                }

                const syncToggle = (input) => {
                    const label = input.closest('label');
                    if (!label) return;
                    const track = label.querySelector('[data-toggle="track"]');
                    const thumb = label.querySelector('[data-toggle="thumb"]');
                    const checked = input.checked;
                    if (track) {
                        track.classList.toggle('bg-green', checked);
                        track.classList.toggle('bg-green-200', !checked);
                    }
                    if (thumb) {
                        thumb.classList.toggle('translate-x-5', checked);
                    }
                };

                if (reemitModal && reemitForm && reemitTrigger && reemitConfirmButton) {
                        const reemitDescriptionTextarea = document.getElementById('reemit-description-textarea');
                        const reemitDiscriminacaoInput = document.getElementById('reemit-discriminacao-input');
                    const reemitSendEmailCheckbox = document.getElementById('reemit-send-email-checkbox');
                    const reemitEmailFields = document.getElementById('nfse_emit_email_fields') || document.getElementById('reemit-email-fields');
                    const reemitEmailToInput = document.getElementById('reemit-email-to-input');
                    const reemitEmailSubjectInput = document.getElementById('reemit-email-subject-input');
                    const reemitEmailBodyInput = document.getElementById('reemit-email-body-input');
                    const reemitAttachDanfseCheckbox = document.getElementById('reemit-attach-danfse-checkbox');
                    const reemitAttachXmlCheckbox = document.getElementById('reemit-attach-xml-checkbox');
                    const reemitSaveDefaultCheckbox = document.getElementById('reemit-save-default-checkbox');
                    const reemitEmailSendHidden = document.getElementById('reemit-email-send-hidden');
                    const reemitEmailToHidden = document.getElementById('reemit-email-to-hidden');
                    const reemitEmailSubjectHidden = document.getElementById('reemit-email-subject-hidden');
                    const reemitEmailBodyHidden = document.getElementById('reemit-email-body-hidden');
                    const reemitEmailAttachDanfseHidden = document.getElementById('reemit-email-attach-danfse-hidden');
                    const reemitEmailAttachXmlHidden = document.getElementById('reemit-email-attach-xml-hidden');
                    const reemitEmailSaveDefaultHidden = document.getElementById('reemit-email-save-default-hidden');

                    const refreshReemitEmailSection = () => {
                        if (reemitEmailFields) {
                            reemitEmailFields.classList.toggle('hidden', !reemitSendEmailCheckbox?.checked);
                        }
                        if (reemitSendEmailCheckbox) {
                            syncToggle(reemitSendEmailCheckbox);
                        }
                    };

                    const closeReemitModal = () => {
                        reemitModal.classList.add('hidden');
                        reemitModal.setAttribute('aria-hidden', 'true');
                        document.body.classList.remove('overflow-hidden');
                    };

                    const openReemitModal = () => {
                        reemitModal.classList.remove('hidden');
                        reemitModal.setAttribute('aria-hidden', 'false');
                        document.body.classList.add('overflow-hidden');
                        reemitConfirmButton.focus();
                    };

                    reemitTrigger.addEventListener('click', openReemitModal);

                    reemitSendEmailCheckbox?.addEventListener('change', refreshReemitEmailSection);
                    reemitAttachDanfseCheckbox?.addEventListener('change', () => syncToggle(reemitAttachDanfseCheckbox));
                    reemitAttachXmlCheckbox?.addEventListener('change', () => syncToggle(reemitAttachXmlCheckbox));
                    reemitSaveDefaultCheckbox?.addEventListener('change', () => syncToggle(reemitSaveDefaultCheckbox));

                    refreshReemitEmailSection();

                    reemitForm.addEventListener('submit', (event) => {
                        if (reemitForm.dataset.reemitConfirmed === '1') {
                            delete reemitForm.dataset.reemitConfirmed;

                            return;
                        }

                        event.preventDefault();
                        openReemitModal();
                    });

                    reemitConfirmButton.addEventListener('click', () => {
                            if (reemitDiscriminacaoInput && reemitDescriptionTextarea) {
                                reemitDiscriminacaoInput.value = reemitDescriptionTextarea.value;
                            }

                            if (reemitEmailSendHidden && reemitSendEmailCheckbox) {
                                reemitEmailSendHidden.value = reemitSendEmailCheckbox.checked ? '1' : '0';
                            }

                            if (reemitEmailToHidden && reemitEmailToInput) {
                                reemitEmailToHidden.value = reemitEmailToInput.value;
                            }

                            if (reemitEmailSubjectHidden && reemitEmailSubjectInput) {
                                reemitEmailSubjectHidden.value = reemitEmailSubjectInput.value;
                            }

                            if (reemitEmailBodyHidden && reemitEmailBodyInput) {
                                reemitEmailBodyHidden.value = reemitEmailBodyInput.value;
                            }

                            if (reemitEmailAttachDanfseHidden && reemitAttachDanfseCheckbox) {
                                reemitEmailAttachDanfseHidden.value = reemitAttachDanfseCheckbox.checked ? '1' : '0';
                            }

                            if (reemitEmailAttachXmlHidden && reemitAttachXmlCheckbox) {
                                reemitEmailAttachXmlHidden.value = reemitAttachXmlCheckbox.checked ? '1' : '0';
                            }

                            if (reemitEmailSaveDefaultHidden && reemitSaveDefaultCheckbox) {
                                reemitEmailSaveDefaultHidden.value = reemitSaveDefaultCheckbox.checked ? '1' : '0';
                            }

                        reemitForm.dataset.reemitConfirmed = '1';
                        closeReemitModal();

                        if (typeof reemitForm.requestSubmit === 'function') {
                            reemitForm.requestSubmit();

                            return;
                        }

                        reemitForm.submit();
                    });

                    reemitModal.querySelectorAll('[data-reemit-close="true"]').forEach((button) => {
                        button.addEventListener('click', closeReemitModal);
                    });
                }
            })();
        </script>
    </x-slot>
</x-layouts.admin>
