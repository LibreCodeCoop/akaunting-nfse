<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\NbsEmissionReadiness;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Re-checks the actual DPS at the last first-POST boundary, not settings
 * observed earlier by an HTTP controller or a queued job.
 *
 * This is not proof of the fiscal event date or external SEFIN validation.
 * The obligation policy is the existing, versioned module contract.
 */
final class FinalDpsIbsCbsReadiness
{
    public function __construct(
        private readonly IbsCbsEmissionReadiness $readiness = new IbsCbsEmissionReadiness(),
        private readonly NbsEmissionReadiness $nbs = new NbsEmissionReadiness(),
    ) {
    }

    /**
     * @return array{isReady:bool,required:bool,effective_date:?string,reason:string,missing:list<string>}
     */
    public function evaluate(DpsData $dps): array
    {
        $enabled = $dps->ibsCbsFinalidade !== null;
        $otherFieldsPresent = $dps->ibsCbsIndFinal !== null
            || trim($dps->ibsCbsCodigoIndicadorOperacao) !== ''
            || $dps->ibsCbsIndDest !== null
            || trim($dps->ibsCbsCst) !== ''
            || trim($dps->ibsCbsClassificacaoTributaria) !== '';

        $assessment = $this->readiness->evaluate(
            competenceDate: trim((string) ($dps->dataCompetencia ?? '')),
            opcaoSimplesNacional: $dps->opcaoSimplesNacional,
            itemListaServico: $dps->itemListaServico,
            settings: [
                'ibs_cbs_enabled' => $enabled,
                'ibs_cbs_ind_final' => $dps->ibsCbsIndFinal === null ? '' : (string) $dps->ibsCbsIndFinal,
                'ibs_cbs_c_ind_op' => $dps->ibsCbsCodigoIndicadorOperacao,
                'ibs_cbs_ind_dest' => $dps->ibsCbsIndDest === null ? '' : (string) $dps->ibsCbsIndDest,
                'ibs_cbs_cst' => $dps->ibsCbsCst,
                'ibs_cbs_c_class_trib' => $dps->ibsCbsClassificacaoTributaria,
            ],
        );

        if (!$enabled && $otherFieldsPresent) {
            return $this->blocked($assessment, 'partial_ibs_cbs_group', ['ibs_cbs_finalidade']);
        }

        if (!$assessment['isReady'] || !$enabled) {
            return $assessment;
        }

        // This LC116 DPS transport implements regular NFS-e only. Credit and
        // debit purposes belong to separate NT009 contracts, not this path.
        if ($dps->ibsCbsFinalidade !== 0) {
            return $this->blocked($assessment, 'unsupported_ibs_cbs_finalidade', ['ibs_cbs_finalidade']);
        }

        if (!in_array($dps->ibsCbsIndFinal, [0, 1], true)) {
            return $this->blocked($assessment, 'invalid_ibs_cbs_ind_final', ['ibs_cbs_ind_final']);
        }

        if (!$this->nbs->valid($dps->codigoNbs, true)) {
            return $this->blocked($assessment, 'invalid_ibs_cbs_nbs', ['codigo_nbs']);
        }

        return $assessment;
    }

    /**
     * @param array{isReady:bool,required:bool,effective_date:?string,reason:string,missing:list<string>} $assessment
     * @param list<string> $missing
     * @return array{isReady:bool,required:bool,effective_date:?string,reason:string,missing:list<string>}
     */
    private function blocked(array $assessment, string $reason, array $missing): array
    {
        return [
            'isReady' => false,
            'required' => $assessment['required'],
            'effective_date' => $assessment['effective_date'],
            'reason' => $reason,
            'missing' => $missing,
        ];
    }
}
