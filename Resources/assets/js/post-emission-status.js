// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfsePostEmissionStatus = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const ACTIVE_STATUSES = new Set(['pending', 'processing', 'retrying']);
    const MAX_POLL_DURATION_MS = 90_000;

    function isActiveStatus(status) {
        return ACTIVE_STATUSES.has(String(status ?? ''));
    }

    function nextPollDelay(elapsedMs) {
        if (elapsedMs < 15_000) {
            return 2_000;
        }

        if (elapsedMs < 45_000) {
            return 5_000;
        }

        return 10_000;
    }

    function shouldPoll(data) {
        return Boolean(data && data.poll === true && data.status === 'processing');
    }

    function translatedStatus(rootNode, status) {
        const key = String(status ?? '')
            .replace(/_([a-z])/g, (_, letter) => letter.toUpperCase())
            .replace(/^([a-z])/, (_, letter) => letter.toUpperCase());

        return rootNode?.dataset?.[`stage${key}`] || String(status ?? '');
    }

    function setArtifactState(rootNode, artifact, info, polling) {
        const link = rootNode.querySelector(`[data-nfse-artifact="${artifact}"]`);

        if (!link) {
            return;
        }

        const ready = Boolean(info?.ready && info?.download_url);
        const spinner = link.querySelector('[data-nfse-artifact-spinner]');
        const label = link.querySelector('[data-nfse-artifact-label]');

        if (ready) {
            link.setAttribute('href', info.download_url);
            link.setAttribute('aria-disabled', 'false');
            link.classList.remove('pointer-events-none', 'opacity-60');
            spinner?.classList.add('hidden');

            if (label && rootNode.dataset.artifactReady) {
                label.textContent = rootNode.dataset.artifactReady;
            }

            return;
        }

        if (polling) {
            link.setAttribute('aria-disabled', 'true');
            link.classList.add('pointer-events-none', 'opacity-60');
            spinner?.classList.remove('hidden');

            if (label && rootNode.dataset.artifactProcessing) {
                label.textContent = rootNode.dataset.artifactProcessing;
            }
        }
    }

    function applyStatus(rootNode, data) {
        if (!rootNode || !data) {
            return;
        }

        const status = String(data.status ?? 'idle');
        rootNode.dataset.nfsePostEmissionState = status;

        const polling = shouldPoll(data);
        const spinner = rootNode.querySelector('[data-nfse-post-emission-spinner]');
        const message = rootNode.querySelector('[data-nfse-post-emission-message]');
        const email = rootNode.querySelector('[data-nfse-email-status]');

        spinner?.classList.toggle('hidden', !polling);

        if (message) {
            const messageKey = status === 'failed'
                ? 'messageFailed'
                : (status === 'completed' ? 'messageCompleted' : 'messageProcessing');
            const translated = rootNode.dataset[messageKey];

            if (translated) {
                message.textContent = translated;
            }
        }

        if (email) {
            email.textContent = translatedStatus(rootNode, data.stages?.email);
        }

        setArtifactState(rootNode, 'xml', data.artifacts?.xml, polling);
        setArtifactState(rootNode, 'danfse', data.artifacts?.danfse, polling);

        const box = rootNode.querySelector('[data-nfse-post-emission-box]');

        if (box) {
            box.classList.toggle('hidden', status === 'idle');
        }
    }

    function start(rootNode, options = {}) {
        if (!rootNode || rootNode.dataset.nfsePolling === 'true') {
            return;
        }

        const url = rootNode.dataset.nfsePostEmissionStatusUrl;

        if (!url || !isActiveStatus(rootNode.dataset.nfsePostEmissionState)) {
            return;
        }

        const fetchImpl = options.fetchImpl ?? globalThis.fetch;
        const setTimeoutImpl = options.setTimeoutImpl ?? globalThis.setTimeout;
        const nowImpl = options.nowImpl ?? Date.now;

        if (typeof fetchImpl !== 'function' || typeof setTimeoutImpl !== 'function') {
            return;
        }

        const startedAt = nowImpl();
        rootNode.dataset.nfsePolling = 'true';

        const poll = async () => {
            const elapsed = Math.max(0, nowImpl() - startedAt);

            if (elapsed >= MAX_POLL_DURATION_MS) {
                rootNode.dataset.nfsePolling = 'false';

                return;
            }

            try {
                const response = await fetchImpl(url, {
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`NFS-e post-emission status returned HTTP ${response.status}`);
                }

                const payload = await response.json();
                const data = payload?.data ?? {};

                applyStatus(rootNode, data);

                if (!shouldPoll(data)) {
                    rootNode.dataset.nfsePolling = 'false';

                    return;
                }
            } catch {
                // Transient polling failures are retried with the same bounded backoff.
            }

            setTimeoutImpl(poll, nextPollDelay(elapsed));
        };

        poll();
    }

    function scan(documentRef, options = {}) {
        documentRef
            ?.querySelectorAll?.('[data-nfse-post-emission-status-url]')
            ?.forEach((rootNode) => start(rootNode, options));
    }

    function boot(documentRef, options = {}) {
        if (!documentRef) {
            return;
        }

        const run = () => scan(documentRef, options);

        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }

        documentRef.addEventListener('click', (event) => {
            const link = event.target?.closest?.('[data-nfse-artifact][aria-disabled="true"]');

            if (link) {
                event.preventDefault();
            }
        });
    }

    return {
        MAX_POLL_DURATION_MS,
        applyStatus,
        boot,
        isActiveStatus,
        nextPollDelay,
        scan,
        shouldPoll,
        start,
    };
});

if (
    typeof document !== 'undefined'
    && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined'
    && globalThis.NfsePostEmissionStatus
) {
    globalThis.NfsePostEmissionStatus.boot(document);
}
