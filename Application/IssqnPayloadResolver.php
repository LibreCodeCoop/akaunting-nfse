<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class IssqnPayloadResolver
{
    /**
     * @param array<string,mixed> $settings
     * @return array{
     *   tributacaoIssqn:int,
     *   issqnPaisResultado:string,
     *   issqnTipoImunidade:?int,
     *   issqnTipoSuspensao:?int,
     *   issqnNumeroProcessoSuspensao:string,
     *   tipoRetencaoIss:int,
     *   requiresSpecialRuntime:bool
     * }
     */
    public function resolve(array $settings): array
    {
        $tributacao = (int) ($settings['tributacao_issqn'] ?? 1);

        if (!in_array($tributacao, [1, 2, 3, 4], true)) {
            $tributacao = 1;
        }

        $tipoRetencao = (int) ($settings['tipo_retencao_iss'] ?? 1);

        if (!in_array($tipoRetencao, [1, 2, 3], true)) {
            $tipoRetencao = 1;
        }

        $paisResultado = $tributacao === 3
            ? strtoupper(trim((string) ($settings['issqn_pais_resultado'] ?? '')))
            : '';

        $tipoImunidadeRaw = trim((string) ($settings['issqn_tipo_imunidade'] ?? ''));
        $tipoImunidade = $tributacao === 2 && in_array($tipoImunidadeRaw, ['1', '2', '3', '4', '5'], true)
            ? (int) $tipoImunidadeRaw
            : null;

        $tipoSuspensaoRaw = trim((string) ($settings['issqn_tipo_suspensao'] ?? ''));
        $tipoSuspensao = $tributacao === 1 && in_array($tipoSuspensaoRaw, ['1', '2'], true)
            ? (int) $tipoSuspensaoRaw
            : null;

        $numeroProcesso = $tributacao === 1
            ? trim((string) ($settings['issqn_numero_processo_suspensao'] ?? ''))
            : '';

        return [
            'tributacaoIssqn' => $tributacao,
            'issqnPaisResultado' => $paisResultado,
            'issqnTipoImunidade' => $tipoImunidade,
            'issqnTipoSuspensao' => $tipoSuspensao,
            'issqnNumeroProcessoSuspensao' => $numeroProcesso,
            'tipoRetencaoIss' => $tipoRetencao,
            'requiresSpecialRuntime' => $tributacao !== 1
                || $tipoRetencao !== 1
                || $tipoSuspensao !== null
                || $numeroProcesso !== '',
        ];
    }
}
