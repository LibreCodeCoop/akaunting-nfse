<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class IbsCbsEmissionReadiness
{
    public function __construct(
        private readonly IbsCbsObligationPolicy $policy = new IbsCbsObligationPolicy(),
    ) {
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{
     *   isReady:bool,
     *   required:bool,
     *   effective_date:?string,
     *   reason:string,
     *   missing:list<string>
     * }
     */
    public function evaluate(
        string $competenceDate,
        int $opcaoSimplesNacional,
        string $itemListaServico,
        array $settings,
    ): array {
        $obligation = $this->policy->evaluate(
            $competenceDate,
            $opcaoSimplesNacional,
            $itemListaServico,
        );

        if (($obligation['reason'] ?? '') === 'unverifiable') {
            return [
                'isReady' => false,
                'required' => false,
                'effective_date' => null,
                'reason' => 'unverifiable',
                'missing' => ['ibs_cbs_obligation_context'],
            ];
        }

        $required = ($obligation['required'] ?? false) === true;
        $enabled = $this->booleanSetting($settings['ibs_cbs_enabled'] ?? false);

        if (!$required && !$enabled) {
            return [
                'isReady' => true,
                'required' => false,
                'effective_date' => $obligation['effective_date'] ?? null,
                'reason' => (string) ($obligation['reason'] ?? 'not_required'),
                'missing' => [],
            ];
        }

        $missing = [];

        if ($required && !$enabled) {
            $missing[] = 'ibs_cbs_enabled';
        }

        $indFinal = trim((string) ($settings['ibs_cbs_ind_final'] ?? ''));
        if ($indFinal !== '' && !in_array($indFinal, ['0', '1'], true)) {
            $missing[] = 'ibs_cbs_ind_final';
        }

        $cIndOp = trim((string) ($settings['ibs_cbs_c_ind_op'] ?? ''));
        if (preg_match('/^\d{6}$/', $cIndOp) !== 1) {
            $missing[] = 'ibs_cbs_c_ind_op';
        }

        $indDest = trim((string) ($settings['ibs_cbs_ind_dest'] ?? ''));
        if (!in_array($indDest, ['0', '1'], true)) {
            $missing[] = 'ibs_cbs_ind_dest';
        }

        $cst = trim((string) ($settings['ibs_cbs_cst'] ?? ''));
        if (preg_match('/^\d{3}$/', $cst) !== 1) {
            $missing[] = 'ibs_cbs_cst';
        }

        $cClassTrib = trim((string) ($settings['ibs_cbs_c_class_trib'] ?? ''));
        if (preg_match('/^\d{6}$/', $cClassTrib) !== 1) {
            $missing[] = 'ibs_cbs_c_class_trib';
        }

        return [
            'isReady' => $missing === [],
            'required' => $required,
            'effective_date' => $obligation['effective_date'] ?? null,
            'reason' => (string) ($obligation['reason'] ?? 'required'),
            'missing' => $missing,
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
