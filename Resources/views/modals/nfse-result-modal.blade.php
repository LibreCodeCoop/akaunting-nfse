{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later

Shared NFS-e operation result modal.
Include this partial in any page that needs emit/cancel feedback.
JS API: window.nfseOpenResultModal(title, bodyHtml, viewUrl, reloadOnClose)
         window.nfseCloseResultModal()
--}}
<div
    id="nfse-result-modal"
    class="fixed inset-0 z-[120] hidden"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="nfse-result-modal-title"
>
    <div class="absolute inset-0 bg-slate-500/55 backdrop-blur-[1px] backdrop-brightness-75"></div>

    <div class="relative flex min-h-full items-center justify-center overflow-y-auto p-4">
        <div class="w-full max-w-2xl rounded-lg bg-white shadow-xl">
            {{-- Header --}}
            <div id="nfse-result-modal-header" class="flex items-center justify-between rounded-t-lg border-b px-5 py-4">
                <div class="flex items-center gap-3">
                    <svg id="nfse-result-modal-icon-success" class="hidden h-6 w-6 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <svg id="nfse-result-modal-icon-error" class="hidden h-6 w-6 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <h3 id="nfse-result-modal-title" class="text-lg font-semibold text-gray-800"></h3>
                </div>
                <button
                    type="button"
                    id="nfse-result-modal-x"
                    data-result-close="true"
                    class="text-gray-400 hover:text-gray-600"
                    aria-label="{{ trans('nfse::general.invoices.cancel_modal_close') }}"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div id="nfse-result-modal-body" class="p-5">
                {{-- Content injected by JS --}}
                <div id="nfse-result-modal-loading" class="hidden flex items-center justify-center py-8">
                    <svg class="h-6 w-6 animate-spin text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </div>
                <div id="nfse-result-modal-content"></div>
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-end gap-2 rounded-b-lg border-t px-5 py-4">
                <a
                    id="nfse-result-modal-view-link"
                    href="#"
                    class="hidden inline-flex items-center rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    {{ trans('nfse::general.invoices.view_nfse') }}
                </a>
                <button
                    type="button"
                    id="nfse-result-modal-close"
                    data-result-close="true"
                    class="inline-flex items-center rounded bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                    {{ trans('nfse::general.invoices.cancel_modal_close') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        let nfseResultReloadOnClose = false;
        const getModal     = () => document.getElementById('nfse-result-modal');
        const getTitle     = () => document.getElementById('nfse-result-modal-title');
        const getContent   = () => document.getElementById('nfse-result-modal-content');
        const getLoading   = () => document.getElementById('nfse-result-modal-loading');
        const getViewLink  = () => document.getElementById('nfse-result-modal-view-link');
        const getIconOk    = () => document.getElementById('nfse-result-modal-icon-success');
        const getIconErr   = () => document.getElementById('nfse-result-modal-icon-error');

        /**
         * @param {string}       title         - Modal header title
         * @param {string}       message       - Plain-text message shown in the body (when partialUrl is absent)
         * @param {string|null}  partialUrl    - URL to fetch and render as the modal body (HTML fragment)
         * @param {string|null}  viewUrl       - URL for the "Ver NFS-e" button; null hides it
         * @param {boolean}      reloadOnClose - Reload the page when the modal is closed
         * @param {boolean}      isSuccess     - Controls icon and header colour
         */
        window.nfseOpenResultModal = (title, message, partialUrl, viewUrl, reloadOnClose, isSuccess) => {
            const resultModal    = getModal();
            const resultTitle    = getTitle();
            const resultContent  = getContent();
            const resultLoading  = getLoading();
            const resultViewLink = getViewLink();
            const resultIconOk   = getIconOk();
            const resultIconErr  = getIconErr();

            if (!resultModal) { return; }

            nfseResultReloadOnClose = Boolean(reloadOnClose);

            if (resultTitle)  { resultTitle.textContent = title ?? ''; }

            if (resultIconOk)  { resultIconOk.classList.toggle('hidden', !isSuccess); }
            if (resultIconErr) { resultIconErr.classList.toggle('hidden', isSuccess); }

            if (resultViewLink) {
                if (viewUrl) {
                    resultViewLink.href = String(viewUrl);
                    resultViewLink.classList.remove('hidden');
                } else {
                    resultViewLink.classList.add('hidden');
                }
            }

            resultModal.classList.remove('hidden');
            resultModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');

            if (partialUrl) {
                if (resultLoading)  { resultLoading.classList.remove('hidden'); }
                if (resultContent)  { resultContent.innerHTML = ''; }

                fetch(partialUrl, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(async (r) => {
                        const html = await r.text();
                        if (resultContent)  { resultContent.innerHTML = html; }
                        if (resultLoading)  { resultLoading.classList.add('hidden'); }
                    })
                    .catch(() => {
                        if (resultContent)  { resultContent.textContent = message ?? ''; }
                        if (resultLoading)  { resultLoading.classList.add('hidden'); }
                    });
            } else {
                if (resultLoading) { resultLoading.classList.add('hidden'); }
                if (resultContent) { resultContent.textContent = message ?? ''; }
            }

            // Attach close listeners lazily (safe to re-attach with once)
            resultModal.querySelectorAll('[data-result-close="true"]').forEach((btn) => {
                btn.addEventListener('click', window.nfseCloseResultModal, { once: true });
            });
        };

        window.nfseCloseResultModal = () => {
            const resultModal = getModal();
            if (!resultModal) { return; }
            resultModal.classList.add('hidden');
            resultModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
            if (nfseResultReloadOnClose) {
                nfseResultReloadOnClose = false;
                window.location.reload();
            }
        };
    })();
</script>
