<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    // Tabs cannot rely on Alpine in AJAX-injected modal HTML, so keep plain JS handlers.
    'nfseTabSyncOnclick' => <<<'JS'
(function (nav) {
    var container = nav.closest('[data-nfse-tabs]');

    if (!container) {
        return;
    }

    if (!container.__nfseSync) {
        container.__nfseSync = function () {
            if (container.__nfseSyncing) {
                return;
            }

            container.__nfseSyncing = true;

            try {
                var activeNav = container.querySelector('[data-nfse-tab-nav].active-tabs')
                    || container.querySelector('[data-nfse-tab-nav]');

                if (!activeNav) {
                    return;
                }

                var activePaneId = activeNav.getAttribute('data-nfse-tab-nav');

                container.querySelectorAll('[data-nfse-tab-pane]').forEach(function (pane) {
                    pane.style.display = pane.id === activePaneId ? '' : 'none';
                });
            } finally {
                container.__nfseSyncing = false;
            }
        };

        container.addEventListener('input', function () {
            container.__nfseSync();
        }, true);

        container.addEventListener('keyup', function () {
            container.__nfseSync();
        }, true);

        container.addEventListener('focusin', function () {
            container.__nfseSync();
        }, true);

        container.__nfseObserver = new MutationObserver(function () {
            container.__nfseSync();
        });

        container.__nfseObserver.observe(container, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['style', 'class'],
        });
    }

    var targetPaneId = nav.getAttribute('data-nfse-tab-nav');

    container.querySelectorAll('[data-nfse-tab-nav]').forEach(function (item) {
        var isActive = item === nav;

        item.classList.toggle('active-tabs', isActive);
        item.classList.toggle('text-purple', isActive);
        item.classList.toggle('border-purple', isActive);

        [
            'after:absolute',
            'after:w-full',
            'after:h-0.5',
            'after:left-0',
            'after:right-0',
            'after:bottom-0',
            'after:bg-purple',
            'after:rounded-tl-md',
            'after:rounded-tr-md',
        ].forEach(function (className) {
            item.classList.toggle(className, isActive);
        });

        item.classList.toggle('text-black', !isActive);
    });

    container.querySelectorAll('[data-nfse-tab-pane]').forEach(function (pane) {
        pane.style.display = pane.id === targetPaneId ? '' : 'none';
    });

    container.__nfseSync();
})(this)
JS,

    'nfseSendEmailOnChange' => <<<'JS'
const target = document.getElementById('nfse-email-fields');

if (target) {
    target.classList.toggle('hidden', !cb.checked);
}

const navList = document.getElementById('nfse-tab-nav-list');

if (navList) {
    navList.classList.toggle('grid-cols-3', cb.checked);
    navList.classList.toggle('grid-cols-2', !cb.checked);
}

const attachmentsNav = document.getElementById('nfse-tab-nav-attachments');

if (attachmentsNav) {
    attachmentsNav.style.display = cb.checked ? '' : 'none';
}

if (!cb.checked) {
    const attachmentsPane = document.getElementById('nfse-tab-pane-attachments');

    if (attachmentsPane && attachmentsPane.style.display !== 'none') {
        const emailNav = document.getElementById('nfse-tab-nav-email');

        if (emailNav) {
            emailNav.click();
        }
    }

    if (attachmentsPane) {
        attachmentsPane.style.display = 'none';
    }
}
JS,

    'nfseRestoreDefaultSync' => <<<'JS'
(function (node) {
    var scope = node.closest('#nfse-email-fields') || document.getElementById('nfse-email-fields');

    if (!scope) {
        return;
    }

    var button = scope.querySelector('[data-nfse-restore-default]');

    if (!button) {
        return;
    }

    var form = button.closest('form');

    if (!form) {
        return;
    }

    var decodeValue = function (value) {
        return value ? decodeURIComponent(value) : '';
    };

    var normalizeHtml = function (html) {
        var normalized = document.createElement('div');

        normalized.innerHTML = html || '';

        normalized.querySelectorAll('br').forEach(function (lineBreak) {
            lineBreak.replaceWith('\n');
        });

        normalized.querySelectorAll('p, div, li').forEach(function (block) {
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
    };

    var resolveBodyState = function () {
        var bodyGroup = scope.querySelector('.ql-editor') ? scope.querySelector('.ql-editor').closest('.relative') : null;
        var bodyGroupVm = bodyGroup && bodyGroup.__vue__ ? bodyGroup.__vue__ : null;
        var htmlEditor = bodyGroupVm && bodyGroupVm.$children
            ? bodyGroupVm.$children.find(function (child) {
                return child.$options && child.$options.name === 'akaunting-html-editor';
            })
            : null;
        var vueEditor = htmlEditor && htmlEditor.$children
            ? htmlEditor.$children.find(function (child) {
                return child.$options && child.$options.name === 'VueEditor';
            })
            : null;
        var quill = vueEditor && vueEditor.quill ? vueEditor.quill : null;
        var editor = quill && quill.root ? quill.root : scope.querySelector('.ql-editor');

        return {
            htmlEditor: htmlEditor,
            vueEditor: vueEditor,
            quill: quill,
            editor: editor,
        };
    };

    var subjectInput = form.querySelector('[name=nfse_email_subject]');
    var bodyState = resolveBodyState();
    var currentSubject = subjectInput ? subjectInput.value : '';
    var currentBody = bodyState.editor ? bodyState.editor.innerHTML : '';
    var defaultSubjectValue = decodeValue(button.getAttribute('data-nfse-default-subject') || '');
    var defaultBodyValue = decodeValue(button.getAttribute('data-nfse-default-body') || '');
    var changed = currentSubject !== defaultSubjectValue
        || normalizeHtml(currentBody) !== normalizeHtml(defaultBodyValue);

    button.style.display = changed ? '' : 'none';
})(this)
JS,

    'nfseRestoreDefaultOnclick' => <<<'JS'
(function (button) {
    var decodeValue = function (value) {
        return value ? decodeURIComponent(value) : '';
    };

    var subject = decodeValue(button.getAttribute('data-nfse-default-subject'));
    var body = decodeValue(button.getAttribute('data-nfse-default-body'));
    var form = button.closest('form');
    var subjectInput = form ? form.querySelector('[name=nfse_email_subject]') : null;

    if (subjectInput) {
        subjectInput.value = subject;
        subjectInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    var emailFields = button.closest('#nfse-email-fields');
    var editorElement = emailFields ? emailFields.querySelector('.ql-editor') : null;
    var quillContainer = emailFields ? emailFields.querySelector('.ql-container') : null;
    var bodyGroup = editorElement ? editorElement.closest('.relative') : null;
    var bodyGroupVm = bodyGroup && bodyGroup.__vue__ ? bodyGroup.__vue__ : null;
    var htmlEditor = bodyGroupVm && bodyGroupVm.$children
        ? bodyGroupVm.$children.find(function (child) {
            return child.$options && child.$options.name === 'akaunting-html-editor';
        })
        : null;
    var vueEditor = htmlEditor && htmlEditor.$children
        ? htmlEditor.$children.find(function (child) {
            return child.$options && child.$options.name === 'VueEditor';
        })
        : null;

    var quill = vueEditor && vueEditor.quill
        ? vueEditor.quill
        : (quillContainer && quillContainer.__quill ? quillContainer.__quill : null);

    if (quill && quill.clipboard && typeof quill.clipboard.dangerouslyPasteHTML === 'function') {
        if (typeof quill.setContents === 'function' && typeof quill.clipboard.convert === 'function') {
            quill.setContents(quill.clipboard.convert(body), 'silent');
        } else {
            quill.clipboard.dangerouslyPasteHTML(0, body);
        }
    } else if (editorElement) {
        editorElement.innerHTML = body;
    }

    if (htmlEditor) {
        htmlEditor.content = body;
        htmlEditor.$emit('input', body);
    }

    if (emailFields) {
        emailFields.dispatchEvent(new Event('input', { bubbles: true }));
    }
})(this)
JS,
];
