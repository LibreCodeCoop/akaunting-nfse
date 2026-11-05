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

    function advancePayload(currentNext, requestedNext, reason, confirmed) {
        if (!validNumber(currentNext) || !validNumber(requestedNext)
            || BigInt(requestedNext) <= BigInt(currentNext)
            || typeof reason !== 'string' || reason.trim().length < 12
            || reason.trim().length > 500 || confirmed !== true) {
            throw new Error('Invalid DPS advance request');
        }
        return {
            expected_next_number: currentNext,
            next_number: requestedNext,
            reason: reason.trim(),
            confirmed: true,
        };
    }

    async function postAdvance(fetchImpl, url, csrf, payload) {
        if (!url || !csrf || !payload || typeof payload !== 'object') {
            throw new Error('Missing DPS administration credentials');
        }
        const response = await fetchImpl(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        });
        if (!response || !response.ok) throw new Error('DPS adjustment blocked');
        const data = await response.json();
        if (!data || !data.data || data.data.next_number !== payload.next_number) {
            throw new Error('Unexpected DPS adjustment response');
        }
        return data.data;
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
        const sequence = panel.querySelector('[data-dps-sequence]');
        const form = panel.querySelector('[data-dps-advance-form]');
        if (!summary || !button || !result || !sequence) return;

        let candidate = null;
        let inFlight = false;

        async function refresh() {
            button.disabled = true;
            if (form) form.hidden = true;
            const raw = await readJson(fetchImpl, panel.dataset.readinessUrl);
            const state = summarize(raw);
            candidate = state.candidate;
            summary.textContent = (state.mechanicallyReady
                ? panel.dataset.readyLabel : panel.dataset.blockedLabel)
                + (state.reasons.length ? ' (' + state.reasons.join(', ') + ')' : '');
            sequence.hidden = !raw.authority_present || !raw.local_present;
            if (!sequence.hidden) {
                sequence.querySelector('[data-dps-series]').textContent = raw.series;
                sequence.querySelector('[data-dps-last]').textContent = raw.last_number;
                sequence.querySelector('[data-dps-next]').textContent = candidate || '-';
            }
            if (candidate) {
                button.disabled = false;
                button.textContent = panel.dataset.lookupLabel.replace(':number', candidate);
                if (form && raw.evidence_recorded && raw.authority_present && raw.local_present) {
                    form.hidden = false;
                    form.querySelector('[data-dps-advance-next]').value = candidate;
                }
            }
        }

        try {
            await refresh();
        } catch (_) {
            candidate = null;
            summary.textContent = panel.dataset.unavailableLabel;
        }

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

        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (inFlight || !candidate) return;
            const feedback = form.querySelector('[data-dps-advance-status]');
            const submit = form.querySelector('[data-dps-advance-button]');
            let payload;
            try {
                payload = advancePayload(candidate,
                    form.querySelector('[data-dps-advance-next]').value,
                    form.querySelector('[data-dps-advance-reason]').value,
                    form.querySelector('[data-dps-advance-confirm]').checked);
            } catch (_) {
                feedback.textContent = panel.dataset.adjustmentFailedLabel;
                return;
            }

            inFlight = true;
            submit.disabled = true;
            try {
                await postAdvance(fetchImpl, form.dataset.writeUrl, form.dataset.csrfToken, payload);
                feedback.textContent = panel.dataset.adjustmentSuccessLabel;
                form.querySelector('[data-dps-advance-reason]').value = '';
                form.querySelector('[data-dps-advance-confirm]').checked = false;
            } catch (_) {
                feedback.textContent = panel.dataset.adjustmentFailedLabel;
            } finally {
                // Reload after any uncertain network result: never replay a
                // potentially committed adjustment without a fresh read.
                try { await refresh(); } catch (_) {
                    candidate = null;
                    form.hidden = true;
                    summary.textContent = panel.dataset.unavailableLabel;
                }
                inFlight = false;
                submit.disabled = false;
            }
        });
    }

    return { validNumber, lookupUrl, readJson, advancePayload, postAdvance, summarize, init };
});
