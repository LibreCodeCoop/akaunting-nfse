// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseIssueModal = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const activeClasses = [
        'active-tabs',
        'text-purple',
        'border-purple',
        'after:absolute',
        'after:w-full',
        'after:h-0.5',
        'after:left-0',
        'after:right-0',
        'after:bottom-0',
        'after:bg-purple',
        'after:rounded-tl-md',
        'after:rounded-tr-md',
    ];

    function nextTabIndex(current, length, key) {
        if (length <= 0) {
            return -1;
        }

        if (key === 'Home') {
            return 0;
        }

        if (key === 'End') {
            return length - 1;
        }

        if (key === 'ArrowRight') {
            return (current + 1) % length;
        }

        if (key === 'ArrowLeft') {
            return (current - 1 + length) % length;
        }

        return current;
    }

    function sendEmailUiState(checked) {
        return {
            fieldsHidden: !checked,
            navColumns: checked ? 3 : 2,
            attachmentsVisible: checked,
        };
    }

    function switchPresentation(checked) {
        return {
            hiddenValue: checked ? '1' : '0',
            trackColor: checked ? '#5e9f4d' : '#dbe8d4',
            thumbLeft: checked ? '1.5rem' : '0.25rem',
        };
    }

    function normalizeHtml(html, documentRef) {
        if (!documentRef || typeof documentRef.createElement !== 'function') {
            return String(html || '')
                .replace(/<br\s*\/?\s*>/gi, '\n')
                .replace(/<\/(p|div|li)>/gi, '\n')
                .replace(/<[^>]*>/g, '')
                .replace(/&nbsp;/gi, ' ')
                .replace(/[ \t]+\n/g, '\n')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
        }

        const normalized = documentRef.createElement('div');
        normalized.innerHTML = html || '';

        normalized.querySelectorAll('br').forEach((lineBreak) => {
            lineBreak.replaceWith('\n');
        });

        normalized.querySelectorAll('p, div, li').forEach((block) => {
            if (block.nextSibling) {
                block.insertAdjacentText('afterend', '\n');
            }
        });

        return (normalized.textContent || '')
            .replace(/\u00a0/g, ' ')
            .replace(/\r\n/g, '\n')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function shouldShowRestore(currentSubject, currentBody, defaultSubject, defaultBody, documentRef) {
        return currentSubject !== defaultSubject
            || normalizeHtml(currentBody, documentRef) !== normalizeHtml(defaultBody, documentRef);
    }

    function syncTabPane(container) {
        if (!container || typeof container.querySelector !== 'function') {
            return;
        }

        const activeNav = container.querySelector('[data-nfse-tab-nav].active-tabs')
            || container.querySelector('[data-nfse-tab-nav]');

        if (!activeNav) {
            return;
        }

        const activePaneId = activeNav.getAttribute('data-nfse-tab-nav');

        container.querySelectorAll('[data-nfse-tab-pane]').forEach((pane) => {
            const active = pane.id === activePaneId;
            pane.style.display = active ? '' : 'none';
            pane.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
    }

    function activateTab(nav) {
        const container = nav && nav.closest ? nav.closest('[data-nfse-tabs]') : null;

        if (!container) {
            return;
        }

        const targetPaneId = nav.getAttribute('data-nfse-tab-nav');

        container.querySelectorAll('[data-nfse-tab-nav]').forEach((item) => {
            const active = item === nav;

            activeClasses.forEach((className) => item.classList.toggle(className, active));
            item.classList.toggle('text-black', !active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.setAttribute('tabindex', active ? '0' : '-1');
        });

        container.querySelectorAll('[data-nfse-tab-pane]').forEach((pane) => {
            const active = pane.id === targetPaneId;
            pane.style.display = active ? '' : 'none';
            pane.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
    }

    function syncSwitch(input) {
        const label = input && input.closest ? input.closest('[data-nfse-switch]') : null;

        if (!label) {
            return;
        }

        const state = switchPresentation(Boolean(input.checked));
        const hidden = label.querySelector('input[type="hidden"]');
        const track = label.querySelector('[data-toggle="track"]');
        const thumb = label.querySelector('[data-toggle="thumb"]');

        if (hidden) {
            hidden.value = state.hiddenValue;

            let node = input.parentElement;

            while (node) {
                if (node.__vue__ && node.__vue__.form && typeof node.__vue__.form === 'object') {
                    if (hidden.name in node.__vue__.form) {
                        node.__vue__.form[hidden.name] = state.hiddenValue;
                        break;
                    }
                }

                node = node.parentElement;
            }
        }

        if (track) {
            track.style.backgroundColor = state.trackColor;
        }

        if (thumb) {
            thumb.style.left = state.thumbLeft;
        }
    }

    function applySendEmailState(checked, documentRef) {
        const state = sendEmailUiState(checked);
        const fields = documentRef.getElementById('nfse-email-fields');
        const navList = documentRef.getElementById('nfse-tab-nav-list');
        const attachmentsNav = documentRef.getElementById('nfse-tab-nav-attachments');
        const attachmentsPane = documentRef.getElementById('nfse-tab-pane-attachments');

        if (fields) {
            fields.classList.toggle('hidden', state.fieldsHidden);
        }

        if (navList) {
            navList.classList.toggle('grid-cols-3', state.navColumns === 3);
            navList.classList.toggle('grid-cols-2', state.navColumns === 2);
        }

        if (attachmentsNav) {
            attachmentsNav.style.display = state.attachmentsVisible ? '' : 'none';
        }

        if (!checked && attachmentsPane) {
            if (attachmentsPane.style.display !== 'none') {
                const emailNav = documentRef.getElementById('nfse-tab-nav-email');

                if (emailNav) {
                    activateTab(emailNav);
                }
            }

            attachmentsPane.style.display = 'none';
            attachmentsPane.setAttribute('aria-hidden', 'true');
        }
    }

    function resolveBodyState(scope) {
        const editor = scope ? scope.querySelector('.ql-editor') : null;
        const bodyGroup = editor ? editor.closest('.relative') : null;
        const bodyGroupVm = bodyGroup && bodyGroup.__vue__ ? bodyGroup.__vue__ : null;
        const htmlEditor = bodyGroupVm && bodyGroupVm.$children
            ? bodyGroupVm.$children.find((child) => child.$options && child.$options.name === 'akaunting-html-editor')
            : null;
        const vueEditor = htmlEditor && htmlEditor.$children
            ? htmlEditor.$children.find((child) => child.$options && child.$options.name === 'VueEditor')
            : null;
        const quill = vueEditor && vueEditor.quill ? vueEditor.quill : null;

        return {
            htmlEditor,
            vueEditor,
            quill,
            editor: quill && quill.root ? quill.root : editor,
        };
    }

    function syncRestoreButton(scope, documentRef) {
        if (!scope) {
            return;
        }

        const button = scope.querySelector('[data-nfse-restore-default]');

        if (!button) {
            return;
        }

        const form = button.closest('form');

        if (!form) {
            return;
        }

        const subjectInput = form.querySelector('[name=nfse_email_subject]');
        const bodyState = resolveBodyState(scope);
        const currentSubject = subjectInput ? subjectInput.value : '';
        const currentBody = bodyState.editor ? bodyState.editor.innerHTML : '';
        const defaultSubject = decodeURIComponent(button.getAttribute('data-nfse-default-subject') || '');
        const defaultBody = decodeURIComponent(button.getAttribute('data-nfse-default-body') || '');

        button.style.display = shouldShowRestore(
            currentSubject,
            currentBody,
            defaultSubject,
            defaultBody,
            documentRef,
        ) ? '' : 'none';
    }

    function restoreDefaults(button, documentRef) {
        const subject = decodeURIComponent(button.getAttribute('data-nfse-default-subject') || '');
        const body = decodeURIComponent(button.getAttribute('data-nfse-default-body') || '');
        const form = button.closest('form');
        const subjectInput = form ? form.querySelector('[name=nfse_email_subject]') : null;

        if (subjectInput) {
            subjectInput.value = subject;
            subjectInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        const scope = button.closest('#nfse-email-fields');
        const bodyState = resolveBodyState(scope);
        const quillContainer = scope ? scope.querySelector('.ql-container') : null;
        const quill = bodyState.quill || (quillContainer && quillContainer.__quill ? quillContainer.__quill : null);

        if (quill && quill.clipboard && typeof quill.clipboard.dangerouslyPasteHTML === 'function') {
            if (typeof quill.setContents === 'function' && typeof quill.clipboard.convert === 'function') {
                quill.setContents(quill.clipboard.convert(body), 'silent');
            } else {
                quill.clipboard.dangerouslyPasteHTML(0, body);
            }
        } else if (bodyState.editor) {
            bodyState.editor.innerHTML = body;
        }

        if (bodyState.htmlEditor) {
            bodyState.htmlEditor.content = body;
            bodyState.htmlEditor.$emit('input', body);
        }

        if (scope) {
            scope.dispatchEvent(new Event('input', { bubbles: true }));
            syncRestoreButton(scope, documentRef);
        }
    }

    function focusErrorSummary(root) {
        if (!root || typeof root.querySelector !== 'function') {
            return false;
        }

        const summary = root.querySelector('[data-nfse-error-summary="true"]');

        if (!summary || summary.__nfseErrorSummaryFocused) {
            return false;
        }

        const visible = summary.offsetParent !== null
            || (typeof summary.getClientRects === 'function' && summary.getClientRects().length > 0);

        if (!visible || typeof summary.focus !== 'function') {
            return false;
        }

        summary.__nfseErrorSummaryFocused = true;
        summary.focus();

        return true;
    }

    function reconcileHydratedModal(documentRef) {
        if (!documentRef || typeof documentRef.querySelectorAll !== 'function') {
            return 0;
        }

        let reconciled = 0;

        documentRef.querySelectorAll('[data-nfse-tabs]').forEach((container) => {
            syncTabPane(container);
            reconciled += 1;
        });

        const sendEmailToggle = typeof documentRef.getElementById === 'function'
            ? documentRef.getElementById('nfse_send_email_toggle')
            : null;

        if (sendEmailToggle) {
            syncSwitch(sendEmailToggle);
            applySendEmailState(Boolean(sendEmailToggle.checked), documentRef);
        }

        const descriptionToggle = typeof documentRef.getElementById === 'function'
            ? documentRef.getElementById('nfse_save_default_description_toggle')
            : null;

        if (descriptionToggle) {
            syncSwitch(descriptionToggle);
        }

        const emailScope = typeof documentRef.getElementById === 'function'
            ? documentRef.getElementById('nfse-email-fields')
            : null;

        if (emailScope) {
            syncRestoreButton(emailScope, documentRef);
        }

        return reconciled;
    }

    function triggerNativeDocumentModal(documentRef) {
        if (!documentRef || typeof documentRef.getElementById !== 'function') {
            return false;
        }

        const triggerIds = [
            'show-slider-actions-send-email-invoice',
            'show-more-actions-send-email-invoice',
        ];

        for (const triggerId of triggerIds) {
            const trigger = documentRef.getElementById(triggerId);

            if (trigger && typeof trigger.click === 'function') {
                trigger.click();
                return true;
            }
        }

        return false;
    }

    function boot(documentRef) {
        if (!documentRef || documentRef.__nfseIssueModalBooted) {
            return;
        }

        documentRef.__nfseIssueModalBooted = true;

        documentRef.addEventListener('click', (event) => {
            const nativeFiscalAction = event.target && event.target.closest
                ? event.target.closest('[data-nfse-native-emit="true"]')
                : null;

            if (nativeFiscalAction) {
                event.preventDefault();
                triggerNativeDocumentModal(documentRef);
                return;
            }

            const nav = event.target && event.target.closest
                ? event.target.closest('[data-nfse-tab-nav]')
                : null;

            if (nav) {
                activateTab(nav);
                return;
            }

            const restore = event.target && event.target.closest
                ? event.target.closest('[data-nfse-restore-default]')
                : null;

            if (restore) {
                restoreDefaults(restore, documentRef);
            }
        });

        documentRef.addEventListener('keydown', (event) => {
            const nav = event.target && event.target.closest
                ? event.target.closest('[data-nfse-tab-nav]')
                : null;

            if (!nav) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                activateTab(nav);
                return;
            }

            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const container = nav.closest('[data-nfse-tabs]');
            const tabs = container
                ? Array.from(container.querySelectorAll('[data-nfse-tab-nav]')).filter((item) => item.offsetParent !== null)
                : [];
            const current = tabs.indexOf(nav);
            const next = nextTabIndex(current, tabs.length, event.key);

            if (next < 0 || !tabs[next]) {
                return;
            }

            event.preventDefault();
            tabs[next].focus();
            activateTab(tabs[next]);
        });

        documentRef.addEventListener('change', (event) => {
            const input = event.target;

            if (!input || !input.matches || !input.matches('[data-nfse-switch-input]')) {
                return;
            }

            syncSwitch(input);

            if (input.id === 'nfse_send_email_toggle') {
                applySendEmailState(Boolean(input.checked), documentRef);
            }

            if (input.id === 'nfse_save_default_description_toggle') {
                const hint = documentRef.getElementById('nfse-description-default-hint');

                if (hint) {
                    hint.classList.toggle('hidden', Boolean(input.checked));
                }
            }
        });

        ['input', 'keyup', 'focusin'].forEach((eventName) => {
            documentRef.addEventListener(eventName, (event) => {
                const scope = event.target && event.target.closest
                    ? event.target.closest('#nfse-email-fields')
                    : null;

                if (scope) {
                    syncRestoreButton(scope, documentRef);
                }

                const tabs = event.target && event.target.closest
                    ? event.target.closest('[data-nfse-tabs]')
                    : null;

                if (tabs) {
                    syncTabPane(tabs);
                }
            }, true);
        });

        const MutationObserverRef = documentRef.defaultView
            ? documentRef.defaultView.MutationObserver
            : (typeof MutationObserver !== 'undefined' ? MutationObserver : null);

        if (typeof MutationObserverRef === 'function') {
            let reconcileScheduled = false;
            const scheduleReconcile = () => {
                if (reconcileScheduled) {
                    return;
                }

                reconcileScheduled = true;
                const windowRef = documentRef.defaultView;
                const schedule = windowRef && typeof windowRef.requestAnimationFrame === 'function'
                    ? windowRef.requestAnimationFrame.bind(windowRef)
                    : (callback) => setTimeout(callback, 0);

                schedule(() => {
                    reconcileScheduled = false;
                    reconcileHydratedModal(documentRef);
                    focusErrorSummary(documentRef);
                });
            };

            const observer = new MutationObserverRef((records) => {
                const relevant = records.some((record) => {
                    const target = record && record.target;

                    if (target && typeof target.closest === 'function' && target.closest('[data-nfse-tabs]')) {
                        return true;
                    }

                    return Array.from(record && record.addedNodes ? record.addedNodes : []).some((node) => {
                        if (!node || node.nodeType !== 1) {
                            return false;
                        }

                        if (typeof node.matches === 'function' && (
                            node.matches('[data-nfse-tabs]')
                            || node.matches('[data-nfse-error-summary="true"]')
                        )) {
                            return true;
                        }

                        return typeof node.querySelector === 'function' && Boolean(
                            node.querySelector('[data-nfse-tabs], [data-nfse-error-summary="true"]'),
                        );
                    });
                });

                if (relevant) {
                    scheduleReconcile();
                }
            });
            const target = documentRef.body || documentRef.documentElement;

            if (target) {
                observer.observe(target, {
                    childList: true,
                    subtree: true,
                });
            }
        }

        reconcileHydratedModal(documentRef);
    }

    return {
        activateTab,
        applySendEmailState,
        boot,
        focusErrorSummary,
        triggerNativeDocumentModal,
        nextTabIndex,
        normalizeHtml,
        reconcileHydratedModal,
        restoreDefaults,
        sendEmailUiState,
        shouldShowRestore,
        switchPresentation,
        syncRestoreButton,
        syncSwitch,
        syncTabPane,
    };
});

if (typeof document !== 'undefined' && typeof globalThis !== 'undefined' && globalThis.NfseIssueModal) {
    globalThis.NfseIssueModal.boot(document);
}
