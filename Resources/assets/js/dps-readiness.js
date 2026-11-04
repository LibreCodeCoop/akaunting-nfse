// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NfseDpsReadiness = api;
    if (root && root.document) {
        const start = () => api.init(root.document, root.fetch.bind(root));
        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const validNumber = (value) => typeof value === 'string'
        && /^[1-9][0-9]{0,14}$/.test(value);

    function lookupUrl(base, number) {
        if (typeof base !== 'string' || !base || !validNumber(number)) {
            throw new Error('Invalid DPS lookup request');
        }
        return base + (base.includes('?') ? '&' : '?') + 'number=' + encodeURIComponent(number);
    }

    async function readJson(fetchImpl, url) {
        const response = await fetchImpl(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response || !response.ok) throw new Error('DPS read failed');
        const payload = await response.json();
        if (!payload || typeof payload !== 'object' || !payload.data) {
            throw new Error('Unexpected DPS JSON response');
        }
        return payload.data;
    }

    function summarize(state) {
        if (!state || typeof state !== 'object' || !Array.isArray(state.reasons)) {
            throw new Error('Invalid DPS status');
        }
        return {
            mechanicallyReady: state.mechanically_ready === true,
            candidate: validNumber(state.candidate_number) ? state.candidate_number : null,
            reasons: state.reasons.filter((reason) => typeof reason === 'string'),
            externallyAttested: false,
        };
    }

    async function init(documentRef, fetchImpl) {
        const panel = documentRef.getElementById('nfse-dps-diagnostics');
        if (!panel) return;
        const summary = panel.querySelector('[data-dps-summary]');
        const button = panel.querySelector('[data-dps-lookup]');
        const result = panel.querySelector('[data-dps-result]');
        if (!summary || !button || !result) return;
        button.disabled = true;

        let candidate = null;
        try {
            const state = summarize(await readJson(fetchImpl, panel.dataset.readinessUrl));
            candidate = state.candidate;
            summary.textContent = (state.mechanicallyReady
                ? panel.dataset.readyLabel : panel.dataset.blockedLabel)
                + (state.reasons.length ? ' (' + state.reasons.join(', ') + ')' : '');
            if (candidate) {
                button.disabled = false;
                button.textContent = panel.dataset.lookupLabel.replace(':number', candidate);
            }
        } catch (_) {
            summary.textContent = panel.dataset.unavailableLabel;
        }

        let inFlight = false;
        button.addEventListener('click', async () => {
            if (inFlight || !candidate) return;
            inFlight = true;
            button.disabled = true;
            result.textContent = panel.dataset.checkingLabel;
            try {
                const evidence = await readJson(fetchImpl, lookupUrl(panel.dataset.lookupUrl, candidate));
                const states = {
                    found: panel.dataset.foundLabel,
                    not_found: panel.dataset.notFoundLabel,
                    inconclusive: panel.dataset.inconclusiveLabel,
                };
                if (evidence.number !== candidate || evidence.safe_to_emit !== false
                    || !Object.prototype.hasOwnProperty.call(states, evidence.official_state)) {
                    throw new Error('Invalid DPS lookup response');
                }
                result.textContent = states[evidence.official_state];
            } catch (_) {
                result.textContent = panel.dataset.unavailableLabel;
            } finally {
                inFlight = false;
                button.disabled = false;
            }
        });
    }

    return { validNumber, lookupUrl, readJson, summarize, init };
});
