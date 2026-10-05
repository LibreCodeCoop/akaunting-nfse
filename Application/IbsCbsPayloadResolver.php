<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class IbsCbsPayloadResolver
{
    /**
     * @param array<string,mixed> $settings
     * @return array{
     *   enabled:bool,
     *   ibsCbsFinalidade:?int,
     *   ibsCbsIndFinal:?int,
     *   ibsCbsCodigoIndicadorOperacao:string,
     *   ibsCbsIndDest:?int,
     *   ibsCbsCst:string,
     *   ibsCbsClassificacaoTributaria:string
     * }
     */
    public function resolve(array $settings): array
    {
        $enabled = $this->booleanSetting($settings['enabled'] ?? false);

        if (!$enabled) {
            return [
                'enabled' => false,
                'ibsCbsFinalidade' => null,
                'ibsCbsIndFinal' => null,
                'ibsCbsCodigoIndicadorOperacao' => '',
                'ibsCbsIndDest' => null,
                'ibsCbsCst' => '',
                'ibsCbsClassificacaoTributaria' => '',
            ];
        }

        $indFinal = trim((string) ($settings['ind_final'] ?? ''));
        $indDest = trim((string) ($settings['ind_dest'] ?? ''));

        return [
            'enabled' => true,
            'ibsCbsFinalidade' => 0,
            'ibsCbsIndFinal' => in_array($indFinal, ['0', '1'], true) ? (int) $indFinal : null,
            'ibsCbsCodigoIndicadorOperacao' => trim((string) ($settings['c_ind_op'] ?? '')),
            'ibsCbsIndDest' => in_array($indDest, ['0', '1'], true) ? (int) $indDest : null,
            'ibsCbsCst' => trim((string) ($settings['cst'] ?? '')),
            'ibsCbsClassificacaoTributaria' => trim((string) ($settings['c_class_trib'] ?? '')),
        ];
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
