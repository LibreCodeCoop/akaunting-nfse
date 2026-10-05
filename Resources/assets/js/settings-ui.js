// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.NfseSettingsUi = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function nextTabIndex(currentIndex, length, key) {
        if (length <= 0) {
            return 0;
        }

        if (key === 'Home') {
            return 0;
        }

        if (key === 'End') {
            return length - 1;
        }

        if (key === 'ArrowRight') {
            return (currentIndex + 1) % length;
        }

        if (key === 'ArrowLeft') {
            return (currentIndex - 1 + length) % length;
        }

        return currentIndex;
    }

    function artifactsCanSave(xmlValue, pdfValue) {
        return String(xmlValue) === '1' || String(pdfValue) === '1';
    }

    function retentionShowsCsll(value) {
        return ['3', '7', '8', '9'].includes(String(value));
    }

    function situationBlocksPiscofins(value) {
        return ['4', '6'].includes(String(value));
    }

    function buildMunicipalQuery(municipioIbge, serviceCode, competence) {
        return {
            municipio_ibge: String(municipioIbge).trim(),
            service_code: String(serviceCode).trim(),
            competence: String(competence).trim(),
        };
    }

    async function init(documentRef, config, fetchImpl, confirmImpl) {

                // ── Tab switcher ────────────────────────────────────────────
                const tabButtons = Array.from(documentRef.querySelectorAll('.tab-button'));

                const activateTab = (btn, focus = false) => {
                    if (!(btn instanceof HTMLButtonElement) || btn.disabled || btn.getAttribute('aria-disabled') === 'true') {
                        return;
                    }

                    tabButtons.forEach((button) => {
                        button.classList.remove('border-green-600', 'text-green-700');
                        button.classList.add('border-transparent', 'text-gray-500');
                        button.setAttribute('aria-selected', 'false');
                        button.setAttribute('tabindex', '-1');
                    });

                    documentRef.querySelectorAll('.tab-panel').forEach((panel) => {
                        panel.classList.add('hidden');
                        panel.setAttribute('aria-hidden', 'true');
                    });

                    btn.classList.add('border-green-600', 'text-green-700');
                    btn.classList.remove('border-transparent', 'text-gray-500');
                    btn.setAttribute('aria-selected', 'true');
                    btn.setAttribute('tabindex', '0');

                    const panel = documentRef.getElementById('tab-panel-' + btn.dataset.tab);
                    panel?.classList.remove('hidden');
                    panel?.setAttribute('aria-hidden', 'false');

                    if (focus) {
                        btn.focus();
                    }
                };

                tabButtons.forEach((btn) => {
                    btn.addEventListener('click', () => activateTab(btn));

                    btn.addEventListener('keydown', (event) => {
                        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                            return;
                        }

                        const enabledTabs = tabButtons.filter((button) => (
                            button instanceof HTMLButtonElement
                            && !button.disabled
                            && button.getAttribute('aria-disabled') !== 'true'
                        ));

                        if (enabledTabs.length === 0) {
                            return;
                        }

                        event.preventDefault();

                        const currentIndex = Math.max(enabledTabs.indexOf(btn), 0);
                        const targetIndex = nextTabIndex(currentIndex, enabledTabs.length, event.key);

                        activateTab(enabledTabs[targetIndex], true);
                    });
                });

                // ── Auth mode toggle (Token / AppRole) ──────────────────────
                const artifactsForm = documentRef.getElementById('artifacts-settings-form');
                const artifactsSaveButton = documentRef.getElementById('artifacts-save-button');
                const artifactsStoreXml = documentRef.getElementById('webdav_store_xml_val');
                const artifactsStorePdf = documentRef.getElementById('webdav_store_pdf_val');

                const canSaveArtifacts = () => {
                    const xmlValue = artifactsStoreXml instanceof HTMLInputElement ? artifactsStoreXml.value : '';
                    const pdfValue = artifactsStorePdf instanceof HTMLInputElement ? artifactsStorePdf.value : '';

                    return artifactsCanSave(xmlValue, pdfValue);
                };

                const syncArtifactsSaveState = () => {
                    if (!(artifactsSaveButton instanceof HTMLButtonElement)) {
                        return;
                    }

                    const enabled = canSaveArtifacts();
                    artifactsSaveButton.disabled = !enabled;
                    artifactsSaveButton.classList.toggle('hover:bg-green-700', enabled);
                    artifactsSaveButton.classList.toggle('opacity-50', !enabled);
                    artifactsSaveButton.classList.toggle('cursor-not-allowed', !enabled);
                };

                globalThis.nfseSyncArtifactSaveState = syncArtifactsSaveState;
                syncArtifactsSaveState();

                artifactsForm?.addEventListener('submit', (event) => {
                    if (canSaveArtifacts()) {
                        return;
                    }

                    event.preventDefault();
                    syncArtifactsSaveState();
                });

                const vaultTokenSection   = documentRef.getElementById('vault-token-section');
                const vaultApproleSection = documentRef.getElementById('vault-approle-section');

                const setSectionVisibility = (element, isVisible) => {
                    if (!element) return;
                    element.hidden = !isVisible;
                    element.classList.toggle('hidden', !isVisible);
                };

                documentRef.querySelectorAll('input[name="auth_mode_ui"]').forEach((radio) => {
                    radio.addEventListener('change', () => {
                        setSectionVisibility(vaultTokenSection, radio.value === 'token');
                        setSectionVisibility(vaultApproleSection, radio.value === 'approle');
                    });
                });

                // ── Certificate tab: read cert + upload button ──────────────
                const btnReadCert       = documentRef.getElementById('btn-read-cert');
                const btnUploadCert     = documentRef.getElementById('btn-upload-cert');
                const pfxFileInput      = documentRef.getElementById('pfx_file');
                const pfxPasswordInput  = documentRef.getElementById('pfx_password');
                const certCnpjDisplay   = documentRef.getElementById('cert-cnpj-display');
                const certCnpjValue     = documentRef.getElementById('cert-cnpj-value');
                const certErrorDisplay  = documentRef.getElementById('cert-error-display');
                const replaceFields     = documentRef.getElementById('replace-cert-fields');
                const showReplaceButton = documentRef.getElementById('btn-show-replace-cert');
                const deleteCertBtn     = documentRef.getElementById('btn-delete-certificate');
                const deleteForm        = documentRef.getElementById('delete-certificate-form');
                const certificateForm   = documentRef.getElementById('certificate-form');
                const csrfToken         = certificateForm?.querySelector('input[name="_token"]')?.value ?? '';

                const parsePfxUrl      = config.parsePfxUrl;
                const hasSavedSettings = config.hasSavedSettings;

                const syncCertButtons = () => {
                    const hasFile     = (pfxFileInput?.files?.length ?? 0) > 0;
                    const hasPassword = (pfxPasswordInput?.value?.trim() ?? '').length > 0;

                    if (btnReadCert) {
                        btnReadCert.disabled = !hasFile || !hasPassword;
                    }

                    if (btnUploadCert) {
                        btnUploadCert.disabled = !hasSavedSettings && (!hasFile || !hasPassword);
                    }
                };

                pfxFileInput?.addEventListener('change', syncCertButtons);
                pfxPasswordInput?.addEventListener('input', syncCertButtons);
                syncCertButtons();

                showReplaceButton?.addEventListener('click', () => {
                    if (replaceFields) replaceFields.hidden = false;
                    pfxFileInput?.focus();
                    syncCertButtons();
                });

                deleteCertBtn?.addEventListener('click', () => {
                    if (!confirmImpl(config.confirmDeleteCertificate)) {
                        return;
                    }
                    deleteForm?.submit();
                });

                btnReadCert?.addEventListener('click', async () => {
                    certErrorDisplay?.classList.add('hidden');
                    certCnpjDisplay?.classList.add('hidden');

                    if (!pfxFileInput?.files?.length) {
                        if (certErrorDisplay) {
                            certErrorDisplay.textContent = config.certificateLabel + ': selecione um arquivo PFX.';
                            certErrorDisplay.classList.remove('hidden');
                        }
                        return;
                    }

                    btnReadCert.disabled = true;

                    const formData = new FormData();
                    formData.append('pfx_file', pfxFileInput.files[0]);
                    formData.append('pfx_password', pfxPasswordInput?.value ?? '');
                    formData.append('_token', csrfToken);

                    try {
                        const response = await fetchImpl(parsePfxUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: formData,
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            if (certErrorDisplay) {
                                certErrorDisplay.textContent = payload.error ?? config.invalidPfx;
                                certErrorDisplay.classList.remove('hidden');
                            }
                            return;
                        }

                        const cnpj = payload.data?.cnpj ?? null;

                        if (!cnpj) {
                            if (certErrorDisplay) {
                                certErrorDisplay.textContent = config.cnpjNotFound;
                                certErrorDisplay.classList.remove('hidden');
                            }
                            return;
                        }

                        if (certCnpjValue) certCnpjValue.textContent = cnpj;
                        certCnpjDisplay?.classList.remove('hidden');
                        syncCertButtons();
                    } catch {
                        if (certErrorDisplay) {
                            certErrorDisplay.textContent = config.invalidPfx;
                            certErrorDisplay.classList.remove('hidden');
                        }
                    } finally {
                        btnReadCert.disabled = false;
                    }
                });

                // ── Federal tab: official-like PIS/COFINS interactions ────
                const federalSituacao = documentRef.getElementById('federal-piscofins-situacao');
                const federalTipoRetencao = documentRef.getElementById('federal-piscofins-tipo-retencao');
                const federalPanel = documentRef.getElementById('federal-piscofins-panel');
                const federalValorCsllRow = documentRef.getElementById('federal-valor-csll-row');
                const federalTributosProfileP = documentRef.getElementById('federal-tributos-profile-p');
                const federalTributosProfileSn = documentRef.getElementById('federal-tributos-profile-sn');
                const federalFields = Array.from(documentRef.querySelectorAll('.federal-piscofins-field'));
                const selectedOpcaoSimplesNacional = String(config.selectedOpcaoSimplesNacional);

                const syncFederalTributosProfileVisibility = () => {
                    // Option 2 means Simples Nacional optant.
                    const isSimplesNacionalOptant = selectedOpcaoSimplesNacional === '2';

                    federalTributosProfileP?.classList.toggle('hidden', isSimplesNacionalOptant);
                    federalTributosProfileSn?.classList.toggle('hidden', !isSimplesNacionalOptant);
                };

                const blockPiscofinsFields = (blockAndZero) => {
                    federalFields.forEach((field) => {
                        if (!(field instanceof HTMLInputElement)) {
                            return;
                        }

                        if (blockAndZero) {
                            field.value = '0.00';
                            field.readOnly = true;
                            field.classList.add('bg-gray-50');
                        } else {
                            if (field.value === '0.00') {
                                field.value = '';
                            }
                            field.readOnly = false;
                            field.classList.remove('bg-gray-50');
                        }
                    });
                };

                const syncFederalCsllVisibility = () => {
                    if (!(federalTipoRetencao instanceof HTMLSelectElement)) {
                        return;
                    }

                    const tipoRetencao = federalTipoRetencao.value;
                    const showCsll = retentionShowsCsll(tipoRetencao);

                    if (federalValorCsllRow) {
                        federalValorCsllRow.classList.toggle('hidden', !showCsll);
                    }
                };

                const syncFederalPanel = () => {
                    if (!(federalSituacao instanceof HTMLSelectElement)) {
                        return;
                    }

                    const situacao = federalSituacao.value;
                    const showPiscofins = situacao !== '' && situacao !== '0';

                    if (federalPanel) {
                        federalPanel.classList.toggle('hidden', !showPiscofins);
                    }

                    if (!showPiscofins) {
                        if (federalTipoRetencao instanceof HTMLSelectElement) {
                            federalTipoRetencao.value = '';
                        }

                        federalFields.forEach((field) => {
                            if (field instanceof HTMLInputElement) {
                                field.value = '';
                                field.readOnly = false;
                                field.classList.remove('bg-gray-50');
                            }
                        });

                        syncFederalCsllVisibility();
                    }

                    blockPiscofinsFields(situationBlocksPiscofins(situacao));
                };

                federalSituacao?.addEventListener('change', () => {
                    syncFederalPanel();
                });

                federalTipoRetencao?.addEventListener('change', () => {
                    syncFederalCsllVisibility();
                });

                syncFederalPanel();
                syncFederalCsllVisibility();
                syncFederalTributosProfileVisibility();

                // ── Fiscal tab: UF / municipality / LC116 ───────────────────
                const ufSelect           = documentRef.getElementById('uf');
                const municipalitySelect = documentRef.getElementById('municipio_nome');
                const ibgeHidden         = documentRef.getElementById('municipio_ibge');
                const ibgeDisplay        = documentRef.getElementById('municipio_ibge_display');
                const fiscalSaveButton   = documentRef.getElementById('federal-save-button');
                const municipalParametersPanel = documentRef.getElementById('municipal-parameters-panel');
                const municipalParametersButton = documentRef.getElementById('municipal-parameters-query');
                const municipalParametersService = documentRef.getElementById('municipal-parameters-service-code');
                const municipalParametersCompetence = documentRef.getElementById('municipal-parameters-competence');
                const municipalParametersStatus = documentRef.getElementById('municipal-parameters-status');
                const municipalParametersResult = documentRef.getElementById('municipal-parameters-result');
                const municipalParametersResultWrapper = documentRef.getElementById('municipal-parameters-result-wrapper');
                if (!ufSelect) {
                    return; // fiscal tab not rendered (no saved settings)
                }

                const syncFiscalSaveButton = () => {
                    if (!(fiscalSaveButton instanceof HTMLButtonElement)) {
                        return;
                    }

                    const canSubmit =
                        ufSelect.value.trim() !== '' &&
                        municipalitySelect.value.trim() !== '' &&
                        ibgeHidden.value.trim() !== '';

                    fiscalSaveButton.disabled = !canSubmit;
                    fiscalSaveButton.setAttribute('aria-disabled', canSubmit ? 'false' : 'true');

                    if (canSubmit) {
                        fiscalSaveButton.classList.remove('bg-gray-300', 'text-gray-500', 'cursor-not-allowed');
                        fiscalSaveButton.classList.add('bg-green-700', 'text-white', 'hover:bg-green-800');

                        return;
                    }

                    fiscalSaveButton.classList.remove('bg-green-700', 'text-white', 'hover:bg-green-800');
                    fiscalSaveButton.classList.add('bg-gray-300', 'text-gray-500', 'cursor-not-allowed');
                };

                const selectedUf              = config.selectedUf;
                const selectedMunicipalityName = config.selectedMunicipalityName;
                const selectedIbge            = config.selectedIbge;

                const ufsUrl                   = config.ufsUrl;
                const municipalitiesUrlTemplate = config.municipalitiesUrlTemplate;

                const fetchJson = async (url) => {
                    const response = await fetchImpl(url, { headers: { Accept: 'application/json' } });
                    const payload  = await response.json().catch(() => ({ data: [] }));
                    if (!response.ok) return [];
                    return Array.isArray(payload.data) ? payload.data : [];
                };

                const renderMunicipalities = (municipalities, selectedName, selectedCode) => {
                    municipalitySelect.innerHTML = '';
                    const placeholder       = documentRef.createElement('option');
                    placeholder.value       = '';
                    placeholder.textContent = 'Selecione...';
                    municipalitySelect.appendChild(placeholder);
                    ibgeHidden.value  = '';
                    ibgeDisplay.value = '';

                    let hasPreselectedMunicipality = false;

                    municipalities.forEach((city) => {
                        const option        = documentRef.createElement('option');
                        option.value        = city.name;
                        option.textContent  = city.name;
                        option.dataset.ibge = city.ibge_code;

                        if ((selectedCode && city.ibge_code === selectedCode) || (!selectedCode && selectedName && city.name === selectedName)) {
                            option.selected   = true;
                            ibgeHidden.value  = city.ibge_code;
                            ibgeDisplay.value = city.ibge_code;
                            hasPreselectedMunicipality = true;
                        }

                        municipalitySelect.appendChild(option);
                    });

                    if (!hasPreselectedMunicipality) {
                        municipalitySelect.value = '';
                    }

                    municipalitySelect.disabled = false;
                    syncFiscalSaveButton();
                };

                const loadMunicipalities = async (uf, preferredName = '', preferredCode = '') => {
                    if (!uf) {
                        municipalitySelect.disabled = true;
                        municipalitySelect.innerHTML = '<option value="">Selecione o estado primeiro...</option>';
                        ibgeHidden.value  = '';
                        ibgeDisplay.value = '';
                        syncFiscalSaveButton();
                        return;
                    }

                    municipalitySelect.disabled = true;
                    municipalitySelect.innerHTML = '<option value="">Carregando municípios...</option>';
                    ibgeHidden.value  = '';
                    ibgeDisplay.value = '';
                    syncFiscalSaveButton();

                    const url            = municipalitiesUrlTemplate.replace('__UF__', encodeURIComponent(uf));
                    const municipalities = await fetchJson(url);
                    renderMunicipalities(municipalities, preferredName, preferredCode);
                };

                ufSelect.addEventListener('change', async () => {
                    await loadMunicipalities(ufSelect.value);
                    syncFiscalSaveButton();
                });

                municipalitySelect.addEventListener('change', () => {
                    const selectedOption = municipalitySelect.options[municipalitySelect.selectedIndex];
                    const ibgeCode       = selectedOption?.dataset?.ibge ?? '';
                    ibgeHidden.value     = ibgeCode;
                    ibgeDisplay.value    = ibgeCode;
                    syncFiscalSaveButton();
                });

                const ufs = await fetchJson(ufsUrl);

                ufSelect.innerHTML = '';
                const ufPlaceholder       = documentRef.createElement('option');
                ufPlaceholder.value       = '';
                ufPlaceholder.textContent = 'Selecione...';
                ufSelect.appendChild(ufPlaceholder);

                ufs.forEach((entry) => {
                    const option       = documentRef.createElement('option');
                    option.value       = entry.uf;
                    option.textContent = `${entry.uf} - ${entry.name}`;
                    if (entry.uf === selectedUf) option.selected = true;
                    ufSelect.appendChild(option);
                });

                if (selectedUf) {
                    await loadMunicipalities(selectedUf, selectedMunicipalityName, selectedIbge);
                }

                if (
                    municipalParametersButton instanceof HTMLButtonElement &&
                    municipalParametersPanel instanceof HTMLElement &&
                    municipalParametersService instanceof HTMLInputElement &&
                    municipalParametersCompetence instanceof HTMLInputElement &&
                    municipalParametersStatus instanceof HTMLElement &&
                    municipalParametersResult instanceof HTMLElement &&
                    municipalParametersResultWrapper instanceof HTMLElement
                ) {
                    municipalParametersButton.addEventListener('click', async () => {
                        const municipioIbge = ibgeHidden.value.trim();
                        const serviceCode = municipalParametersService.value.trim();
                        const competence = municipalParametersCompetence.value.trim();

                        municipalParametersStatus.textContent = '';
                        municipalParametersResult.textContent = '';
                        municipalParametersResultWrapper.classList.add('hidden');

                        if (!municipioIbge || !serviceCode || !competence) {
                            municipalParametersStatus.textContent = config.municipalInvalidService;
                            return;
                        }

                        const baseUrl = municipalParametersPanel.dataset.url ?? '';
                        const params = new URLSearchParams(buildMunicipalQuery(
                            municipioIbge,
                            serviceCode,
                            competence,
                        ));

                        municipalParametersButton.disabled = true;
                        municipalParametersStatus.textContent = config.municipalQuerying;

                        try {
                            const response = await fetchImpl(`${baseUrl}?${params.toString()}`, {
                                headers: { Accept: 'application/json' },
                            });
                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                municipalParametersStatus.textContent =
                                    typeof payload.message === 'string'
                                        ? payload.message
                                        : config.municipalQueryFailed;
                                return;
                            }

                            municipalParametersResult.textContent = JSON.stringify({
                                data: payload.data ?? {},
                                source: payload.meta ?? {},
                            }, null, 2);
                            municipalParametersResultWrapper.classList.remove('hidden');
                            municipalParametersStatus.textContent =
                                payload.meta?.stale === true
                                    ? config.municipalCachedWarning
                                    : config.municipalLiveSource;
                        } catch (error) {
                            municipalParametersStatus.textContent = config.municipalQueryFailed;
                        } finally {
                            municipalParametersButton.disabled = false;
                        }
                    });
                }

                syncFiscalSaveButton();

    }

    function boot(documentRef, fetchImpl, confirmImpl) {
        if (!documentRef) {
            return;
        }

        const configNode = documentRef.getElementById('nfse-settings-config');
        let config = {};

        try {
            config = JSON.parse(configNode?.textContent || '{}');
        } catch {
            config = {};
        }

        const run = () => init(documentRef, config, fetchImpl, confirmImpl);

        if (documentRef.readyState === 'loading') {
            documentRef.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    }

    return {
        artifactsCanSave,
        buildMunicipalQuery,
        init,
        nextTabIndex,
        retentionShowsCsll,
        situationBlocksPiscofins,
        boot,
    };
});

if (
    typeof document !== 'undefined'
    && typeof fetch !== 'undefined'
    && typeof globalThis !== 'undefined'
    && globalThis.NfseSettingsUi
) {
    globalThis.NfseSettingsUi.boot(
        document,
        fetch,
        typeof confirm === 'function' ? confirm : () => false,
    );
}
