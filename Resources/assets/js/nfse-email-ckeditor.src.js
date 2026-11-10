/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {
    ClassicEditor,
    Essentials,
    Paragraph,
    Bold,
    Italic,
    Underline,
    List,
    Link,
} from 'ckeditor5';
import 'ckeditor5/ckeditor5.css';

const instances = new WeakMap();
const pending = new WeakMap();

function escapeHtml(value) {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function initialHtml(value) {
    const html = String(value ?? '');
    if (/<[a-z][a-z0-9:-]*[\s/>]/i.test(html)) {
        return html;
    }
    return html.split(/\r?\n/).map(line => '<p>' + (escapeHtml(line) || '&nbsp;') + '</p>').join('');
}

function notifySource(source, html) {
    if (source.value === html) {
        return;
    }
    source.value = html;
    source.dispatchEvent(new Event('input', { bubbles: true }));
}

function attach(source) {
    if (instances.has(source) || pending.has(source)) {
        return;
    }

    // Vue may initialize the form field to an empty string after Blade rendered
    // the default body. Keep that server-rendered value as the editor's seed.
    const expectedAtStart = initialHtml(source.value || source.defaultValue);
    source.value = expectedAtStart;
    source.dispatchEvent(new Event('input', { bubbles: true }));
    const promise = ClassicEditor.create(source, {
        licenseKey: 'GPL',
        plugins: [Essentials, Paragraph, Bold, Italic, Underline, List, Link],
        toolbar: {
            items: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', '|', 'link'],
            shouldNotGroupWhenFull: true,
        },
        link: { addTargetToExternalLinks: true },
        placeholder: source.getAttribute('data-nfse-editor-placeholder') || '',
    });

    pending.set(source, promise);

    promise.then(editor => {
        pending.delete(source);
        if (!source.isConnected) {
            void editor.destroy();
            return;
        }
        instances.set(source, editor);

        const editable = editor.ui.view.editable.element;
        if (editable) {
            editable.setAttribute('aria-label', source.getAttribute('data-nfse-editor-label') || 'Corpo do e-mail');
        }
        editor.model.document.on('change:data', () => notifySource(source, editor.getData()));

        // ClassicEditor.create(textarea) may already have escaped its contents.
        // Use the HTML captured before initialization, not that mutated value.
        editor.setData(expectedAtStart);
        // CKEditor normalizes markup; the Vue Form must use that value on submit.
        source.value = editor.getData();
        source.dispatchEvent(new Event('input', { bubbles: true }));
    }).catch(() => {
        pending.delete(source);
        source.classList.remove('sr-only');
        source.removeAttribute('aria-hidden');
        source.dataset.nfseEditorUnavailable = 'true';
    });
}

function boot(root = document) {
    root.querySelectorAll('[data-nfse-ckeditor]').forEach(attach);

    if (root.__nfseCkeditorObserved || !root.defaultView?.MutationObserver) {
        return;
    }

    root.__nfseCkeditorObserved = true;
    const observer = new root.defaultView.MutationObserver(changes => {
        for (const change of changes) {
            for (const node of change.removedNodes) {
                if (node.nodeType !== 1) {
                    continue;
                }
                const removedSources = [
                    ...(node.matches?.('[data-nfse-ckeditor]') ? [node] : []),
                    ...Array.from(node.querySelectorAll?.('[data-nfse-ckeditor]') || []),
                ];
                for (const source of removedSources) {
                    if (source.isConnected) {
                        continue;
                    }
                    const editor = instances.get(source);
                    if (editor) {
                        instances.delete(source);
                        void editor.destroy();
                    }
                }
            }
            for (const node of change.addedNodes) {
                if (node.nodeType !== 1) {
                    continue;
                }
                if (node.matches?.('[data-nfse-ckeditor]')) {
                    attach(node);
                }
                node.querySelectorAll?.('[data-nfse-ckeditor]').forEach(attach);
            }
        }
    });
    observer.observe(root.documentElement, { childList: true, subtree: true });
}

function get(source) {
    return source ? instances.get(source) ?? null : null;
}

function getData(source) {
    return get(source)?.getData() ?? source?.value ?? '';
}

function setData(source, value) {
    if (!source) {
        return;
    }
    const html = String(value ?? '');
    const editor = get(source);
    if (editor) {
        editor.setData(initialHtml(html));
        notifySource(source, editor.getData());
        return;
    }
    notifySource(source, html);
}

globalThis.NfseEmailEditor = { boot, get, getData, setData };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => boot(), { once: true });
} else {
    boot();
}