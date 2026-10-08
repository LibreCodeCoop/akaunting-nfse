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

        if (($obligation['required'] ?? false) !== true) {
            return [
                'isReady' => true,
                'required' => false,
                'effective_date' => $obligation['effective_date'] ?? null,
                'reason' => (string) ($obligation['reason'] ?? 'not_required'),
                'missing' => [],
            ];
        }

        $missing = [];

        if (!$this->booleanSetting($settings['ibs_cbs_enabled'] ?? false)) {
            $missing[] = 'ibs_cbs_enabled';
        }

        if (trim((string) ($settings['ibs_cbs_c_ind_op'] ?? '')) === '') {
            $missing[] = 'ibs_cbs_c_ind_op';
        }

        $indDest = trim((string) ($settings['ibs_cbs_ind_dest'] ?? ''));
        if (!in_array($indDest, ['0', '1'], true)) {
            $missing[] = 'ibs_cbs_ind_dest';
        }

        if (trim((string) ($settings['ibs_cbs_cst'] ?? '')) === '') {
            $missing[] = 'ibs_cbs_cst';
        }

        if (trim((string) ($settings['ibs_cbs_c_class_trib'] ?? '')) === '') {
            $missing[] = 'ibs_cbs_c_class_trib';
        }

        return [
            'isReady' => $missing === [],
            'required' => true,
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
