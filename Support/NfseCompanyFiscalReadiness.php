<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Application\SpecialTaxRegimePolicy;

/**
 * Administrative fiscal checklist, never an authorization to issue a DPS.
 * It deliberately does not contact or depend on the live Vault.
 */
final class NfseCompanyFiscalReadiness
{
    /** @return array{basic_complete:bool,pending:list<string>,municipal_review_required:bool} */
    public function evaluate(array $profile, ?int $now = null): array
    {
        $fields = $profile['fiscal_parameters'] ?? [];
        $pending = [];

        if (($profile['status'] ?? '') !== 'active') {
            $pending[] = 'profile_status';
        }

        $clock = $now ?? time();
        if (empty($profile['certificate_valid_from']) || empty($profile['certificate_valid_to'])
            || $clock < (int) $profile['certificate_valid_from'] || $clock > (int) $profile['certificate_valid_to']) {
            $pending[] = 'certificate_validity';
        }

        if (preg_match('/^[A-Za-z]{2}$/D', (string) ($fields['uf'] ?? '')) !== 1) {
            $pending[] = 'uf';
        }

        if (preg_match('/^[0-9]{7}$/D', (string) ($fields['municipio_ibge'] ?? '')) !== 1) {
            $pending[] = 'municipio_ibge';
        }

        $simples = (string) ($fields['opcao_simples_nacional'] ?? '');
        if (!in_array($simples, ['1', '2', '3'], true)) {
            $pending[] = 'opcao_simples_nacional';
        }

        $issqn = (string) ($fields['tributacao_issqn'] ?? '');
        if (!in_array($issqn, ['1', '2', '3', '4'], true)) {
            $pending[] = 'tributacao_issqn';
        }

        if (!in_array((string) ($fields['tipo_retencao_iss'] ?? ''), ['1', '2', '3'], true)) {
            $pending[] = 'tipo_retencao_iss';
        }

        // These conditional ISSQN details are administrative metadata. Missing
        // inputs must remain visible for review, never inferred or defaulted.
        if ($issqn === '3') {
            $country = strtoupper(trim((string) ($fields['issqn_pais_resultado'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1 || $country === 'BR') {
                $pending[] = 'issqn_pais_resultado';
            }
        }

        if ($issqn === '2' && !in_array((string) ($fields['issqn_tipo_imunidade'] ?? ''), ['1', '2', '3', '4', '5'], true)) {
            $pending[] = 'issqn_tipo_imunidade';
        }

        if ($issqn === '1') {
            $suspension = trim((string) ($fields['issqn_tipo_suspensao'] ?? ''));
            $process = trim((string) ($fields['issqn_numero_processo_suspensao'] ?? ''));
            if ($suspension !== '' || $process !== '') {
                if (!in_array($suspension, ['1', '2'], true)) {
                    $pending[] = 'issqn_tipo_suspensao';
                }
                if (preg_match('/^\d{30}$/D', $process) !== 1) {
                    $pending[] = 'issqn_numero_processo_suspensao';
                }
            }
        }

        $regime = (new SpecialTaxRegimePolicy())->evaluate(
            $fields['regime_especial_tributacao'] ?? null,
            (int) $simples,
            (int) $issqn,
            null,
            $fields['regime_apuracao_simples_nacional'] ?? null,
        );

        if (!$regime['can_emit']) {
            $pending[] = $regime['reason'] === 'simples_apuration_not_configured'
                ? 'regime_apuracao_simples_nacional' : 'regime_especial_tributacao';
        }

        return [
            'basic_complete' => $pending === [],
            'pending' => $pending,
            'municipal_review_required' => $regime['municipal_review_required'],
        ];
    }
}
