<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Code;

/**
 * Conservative application of LC 116/2003, article 3.
 *
 * A municipality used for an issuer's establishment is not automatically the
 * ISS incidence municipality. Exception cases need an independently established
 * fiscal location. No municipality administration/authorization is inferred.
 */
final class MunicipalIncidenceResolver
{
    /** @var list<string> */
    private const LOCATION_EXCEPTIONS = [
        '0304', '0305', '0422', '0423', '0509',
        '0702', '0704', '0705', '0709', '0710', '0711', '0712',
        '0716', '0717', '0718', '0719', '1414',
        '1101', '1102', '1104', '1501', '1509', '1705', '1710', '2201',
    ];

    /**
     * @param array<string,mixed> $facts Trusted fiscal facts for the selected
     * invoice/group, not arbitrary request or item-catalog values.
     * @return array{municipio_ibge:?string,source:string,reason:string}
     */
    public function resolve(array $facts): array
    {
        $declared = trim((string) ($facts['incidence_municipality'] ?? ''));
        if ($declared !== '') {
            if (preg_match('/^\d{7}$/D', $declared) !== 1) {
                return $this->unknown('invalid_declared_incidence');
            }

            // The issuer must independently verify the basis under LC 116. A
            // bare user-supplied municipality must not be promoted to a finding.
            if (($facts['incidence_basis'] ?? '') !== 'documented_fiscal_context') {
                return $this->unknown('incidence_basis_not_established');
            }

            return [
                'municipio_ibge' => $declared,
                'source' => 'documented_fiscal_context',
                'reason' => 'explicit_iss_incidence',
            ];
        }

        $service = Lc116Code::normalize($facts['item_lista_servico'] ?? '');
        if ($service === '') {
            return $this->unknown('lc116_service_unavailable');
        }

        if (($facts['service_from_abroad'] ?? false) === true) {
            return $this->unknown('foreign_service_incidence_requires_review');
        }

        if (is_numeric($facts['tributacao_issqn'] ?? null)
            && (int) $facts['tributacao_issqn'] !== 1) {
            return $this->unknown('special_iss_taxation_requires_review');
        }

        if ($this->requiresLocationEvidence($service)) {
            return $this->unknown('lc116_location_exception_requires_review');
        }

        // The ordinary article 3 rule is safe only if the configured
        // municipality is affirmatively the establishment carrying out the
        // service. No provider domicile fallback is invented here.
        if (($facts['provider_establishment_confirmed'] ?? false) !== true) {
            return $this->unknown('provider_establishment_not_confirmed');
        }

        $provider = trim((string) ($facts['provider_establishment_municipality'] ?? ''));
        if (preg_match('/^\d{7}$/D', $provider) !== 1) {
            return $this->unknown('provider_establishment_unavailable');
        }

        return [
            'municipio_ibge' => $provider,
            'source' => 'lc116_art3_default',
            'reason' => 'provider_establishment_confirmed',
        ];
    }

    private function requiresLocationEvidence(string $service): bool
    {
        if (in_array($service, self::LOCATION_EXCEPTIONS, true)) {
            return true;
        }

        // LC 116, art. 3, XVIII (item 12 except 12.13); XIX (item 16);
        // XXII (item 20). Several other subitems appear above.
        return (str_starts_with($service, '12') && $service !== '1213')
            || str_starts_with($service, '16')
            || str_starts_with($service, '20');
    }

    /** @return array{municipio_ibge:null,source:string,reason:string} */
    private function unknown(string $reason): array
    {
        return ['municipio_ibge' => null, 'source' => 'unverifiable', 'reason' => $reason];
    }
}
