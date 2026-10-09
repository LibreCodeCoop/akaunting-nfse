<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Application\MunicipalIncidenceResolver;

/**
 * Maps the actual invoice and selected fiscal group into #374 evidence inputs.
 *
 * This intentionally does not query municipal APIs or block/issue an NFS-e.
 * The later issuance integration (#376) must supply DPS identity/fingerprint
 * after constructing the final submission and must re-check at POST time.
 */
final class InvoiceMunicipalEvidenceContextResolver
{
    public function __construct(
        private readonly InvoiceDpsIdentity $identity = new InvoiceDpsIdentity(),
        private readonly MunicipalIncidenceResolver $incidence = new MunicipalIncidenceResolver(),
    ) {
    }

    /**
     * @param array<string,mixed> $group The selected, persisted fiscal group.
     * @param array<string,mixed> $settings Company-scoped issuer settings.
     * @param array<string,mixed> $fiscalFacts Documented incidence evidence,
     *   if available. No request-level municipality is trusted by default.
     * @return array<string,mixed>
     */
    public function resolve(Invoice $invoice, array $group, array $settings, array $fiscalFacts = []): array
    {
        $national = trim((string) ($group['codigo_tributacao_nacional'] ?? ''));
        $municipal = trim((string) ($group['codigo_tributacao_municipal'] ?? ''));
        $municipal = $municipal === '' ? '000' : $municipal;
        $serviceCode = preg_match('/^\d{6}$/D', $national) === 1
            && preg_match('/^\d{3}$/D', $municipal) === 1
            ? $national . $municipal
            : '';

        $location = $this->incidence->resolve([
            'item_lista_servico' => $group['item_lista_servico'] ?? '',
            'provider_establishment_municipality' => $settings['municipio_ibge'] ?? '',
            'provider_establishment_confirmed' => ($fiscalFacts['provider_establishment_confirmed'] ?? false) === true,
            'incidence_municipality' => $fiscalFacts['incidence_municipality'] ?? '',
            'incidence_basis' => $fiscalFacts['incidence_basis'] ?? '',
            'service_from_abroad' => ($fiscalFacts['service_from_abroad'] ?? false) === true,
            'tributacao_issqn' => $settings['tributacao_issqn'] ?? null,
        ]);

        return [
            'company_id' => is_numeric($invoice->company_id ?? null) ? (int) $invoice->company_id : 0,
            'environment' => filter_var($settings['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOL)
                ? 'sandbox'
                : 'production',
            'incidence_municipality' => $location['municipio_ibge'] ?? '',
            'incidence_source' => $location['source'],
            'incidence_reason' => $location['reason'],
            'competence' => $this->identity->competenceDate($invoice) ?? '',
            'national_code' => $national,
            'municipal_complement' => $municipal,
            'service_code' => $serviceCode,
            'dps_id' => is_string($fiscalFacts['dps_id'] ?? null) ? $fiscalFacts['dps_id'] : '',
            'dps_sha256' => is_string($fiscalFacts['dps_sha256'] ?? null) ? $fiscalFacts['dps_sha256'] : '',
        ];
    }
}
