/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function (root) {
    'use strict';

    const controllers = new WeakMap();

    function isEmail(value) {
        const email = String(value || '').trim();
        return email.length <= 254 && email.length > 3
            && /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]+$/.test(email);
    }

    function splitAddresses(value) {
        return String(value || '').split(/[,;\r\n]+/).map((part) => part.trim()).filter(Boolean);
    }

    function attach(source) {
        if (controllers.has(source)) {
            return;
        }

        const doc = source.ownerDocument;
        const entries = [];
        const shell = doc.createElement('div');
        shell.className = 'nfse-recipient-chipbox';
        shell.setAttribute('data-nfse-recipient-chipbox', source.name || source.id);

        const entry = doc.createElement('input');
        entry.type = 'text';
        entry.id = source.id + '-entry';
        entry.className = 'nfse-recipient-chip-entry';
        entry.autocomplete = 'off';
        entry.setAttribute('aria-label', source.getAttribute('data-nfse-recipient-label') || 'Endereços de e-mail');
        entry.placeholder = source.getAttribute('data-nfse-recipient-placeholder') || 'Digite um e-mail';
        if (source.hasAttribute('aria-describedby')) {
            entry.setAttribute('aria-describedby', source.getAttribute('aria-describedby'));
        }
        const message = doc.createElement('p');
        message.className = 'nfse-recipient-validation';
        message.setAttribute('role', 'status');
        message.setAttribute('aria-live', 'polite');

        shell.appendChild(entry);
        source.insertAdjacentElement('afterend', shell);
        shell.insertAdjacentElement('afterend', message);
        const label = doc.querySelector('label[for="' + source.id + '"]');
        if (label) {
            label.htmlFor = entry.id;
        }
        source.hidden = true;
        source.setAttribute('aria-hidden', 'true');
        source.setAttribute('tabindex', '-1');
        source.addEventListener('focus', () => entry.focus());

        function showError(validate) {
            const invalid = validate && entry.value.trim() !== '' && !isEmail(entry.value.trim());
            entry.setAttribute('aria-invalid', invalid ? 'true' : 'false');
            message.textContent = invalid ? 'Confira o endereço de e-mail antes de enviar.' : '';
        }

        function sync(validate = false) {
            const addressList = entries.concat(entry.value.trim() ? [entry.value.trim()] : []);
            const serialized = addressList.join(', ');
            if (source.value !== serialized) {
                source.value = serialized;
                source.dispatchEvent(new Event('input', { bubbles: true }));
            }
            showError(validate);
        }

        function remove(address) {
            const i = entries.indexOf(address);
            if (i !== -1) {
                entries.splice(i, 1);
                render();
                sync();
                entry.focus();
            }
        }

        function render() {
            shell.querySelectorAll('[data-nfse-recipient-chip]').forEach((chip) => chip.remove());
            entries.forEach((address) => {
                const chip = doc.createElement('span');
                chip.className = 'nfse-recipient-chip';
                chip.dataset.nfseRecipientChip = 'true';
                const text = doc.createElement('span');
                text.textContent = address;
                const close = doc.createElement('button');
                close.type = 'button';
                close.className = 'nfse-recipient-chip-remove';
                close.textContent = '×';
                close.setAttribute('aria-label', 'Remover ' + address);
                close.addEventListener('click', () => remove(address));
                chip.append(text, close);
                shell.insertBefore(chip, entry);
            });
        }

        function add(value) {
            const address = value.trim().toLowerCase();
            if (!isEmail(address)) {
                return false;
            }
            if (!entries.includes(address)) {
                entries.push(address);
                render();
            }
            return true;
        }

        function commit() {
            const value = entry.value.trim();
            if (value === '') {
                return true;
            }
            const parts = splitAddresses(value);
            const invalid = [];
            parts.forEach((item) => { if (!add(item)) invalid.push(item); });
            entry.value = invalid.join(', ');
            sync(invalid.length > 0);
            return invalid.length === 0;
        }

        function extractCompleted() {
            if (!/[,;\n]/.test(entry.value)) {
                sync();
                return;
            }
            const parts = entry.value.split(/[,;\n]/);
            const trailing = parts.pop() || '';
            const invalid = [];
            parts.forEach((item) => {
                if (item.trim() && !add(item)) invalid.push(item.trim());
            });
            entry.value = [...invalid, trailing.trim()].filter(Boolean).join(', ');
            sync(invalid.length > 0);
        }

        entry.addEventListener('input', extractCompleted);
        entry.addEventListener('blur', commit);
        entry.addEventListener('keydown', (event) => {
            const key = event.key;
            if (key === 'Backspace' && entry.value === '' && entries.length) {
                remove(entries[entries.length - 1]);
                event.preventDefault();
                return;
            }
            const separator = key === ',' || key === ';' || key === 'Enter';
            const confirmed = key === 'Tab' || key === ' ' && isEmail(entry.value.trim());
            if (!separator && !confirmed) {
                return;
            }
            if (separator) {
                event.preventDefault();
            }
            commit();
        });
        entry.addEventListener('paste', (event) => {
            const text = event.clipboardData?.getData('text') || '';
            if (!/[,;\s]/.test(text)) {
                return;
            }
            event.preventDefault();
            const parts = (entry.value + ' ' + text).split(/[,;\s]+/).filter(Boolean);
            const invalid = [];
            parts.forEach((item) => { if (!add(item)) invalid.push(item); });
            entry.value = invalid.join(', ');
            sync(invalid.length > 0);
            entry.focus();
        });

        const initial = (source.value || source.defaultValue || '').trim();
        const invalid = [];
        splitAddresses(initial).forEach((item) => { if (!add(item)) invalid.push(item); });
        entry.value = invalid.join(', ');
        controllers.set(source, { entry, commit });
        sync();
        // Populate Akaunting's Vue Form even when it initially hydrated an empty field.
        source.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function boot(rootNode) {
        rootNode.querySelectorAll('[data-nfse-recipient-input]').forEach(attach);
    }

    function focus(source) {
        const controller = controllers.get(source);
        (controller?.entry || source)?.focus();
    }

    root.NfseRecipientChips = { boot, focus, splitAddresses, isEmail };

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => boot(document), { once: true });
        } else {
            boot(document);
        }
        const observer = new MutationObserver((records) => {
            records.forEach((record) => {
                record.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if (node.matches?.('[data-nfse-recipient-input]')) {
                        attach(node);
                    }
                    node.querySelectorAll?.('[data-nfse-recipient-input]').forEach(attach);
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { splitAddresses, isEmail };
    }
})(typeof window !== 'undefined' ? window : globalThis);