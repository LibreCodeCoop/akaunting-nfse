<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Consolidated side-effect-free NFS-e issuance readiness evaluation.
 *
 * Adapters are responsible for checking external/local capabilities (for
 * example whether the certificate secret exists) and pass those facts here.
 */
final class EmissionReadiness
{
    /**
     * @param array<string, mixed> $settings
     * @return array{checklist:array<string,bool>,isReady:bool}
     */
    public function evaluate(
        array $settings,
        bool $hasLocalCertificate,
        bool $hasCertificateSecret,
        string $serviceCode,
        ?int $now = null,
    ): array {
        $cnpj = trim((string) ($settings['cnpj_prestador'] ?? ''));

        $checklist = [
            'cnpj_prestador' => $cnpj !== '',
            'municipio_ibge' => trim((string) ($settings['municipio_ibge'] ?? '')) !== '',
            'item_lista_servico' => trim($serviceCode) !== '',
            'bao_addr' => trim((string) ($settings['bao_addr'] ?? '')) !== '',
            'bao_mount' => trim((string) ($settings['bao_mount'] ?? '')) !== '',
            'certificate' => $hasLocalCertificate,
            'certificate_secret' => $hasCertificateSecret,
        ];

        $validFrom = (int) ($settings['certificate_valid_from'] ?? 0);
        $validTo = (int) ($settings['certificate_valid_to'] ?? 0);

        if ($validFrom > 0 && $validTo > $validFrom) {
            $clock = $now ?? time();
            $checklist['certificate_valid'] = $clock >= $validFrom && $clock <= $validTo;
        }

        if ($this->booleanSetting($settings['ibs_cbs_enabled'] ?? false)) {
            $indDest = trim((string) ($settings['ibs_cbs_ind_dest'] ?? ''));
            $checklist['ibs_cbs'] =
                trim((string) ($settings['ibs_cbs_c_ind_op'] ?? '')) !== ''
                && in_array($indDest, ['0', '1'], true)
                && trim((string) ($settings['ibs_cbs_cst'] ?? '')) !== ''
                && trim((string) ($settings['ibs_cbs_c_class_trib'] ?? '')) !== '';
        }

        return [
            'checklist' => $checklist,
            'isReady' => !in_array(false, $checklist, true),
        ];
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
