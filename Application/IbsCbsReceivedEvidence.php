<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\ExactTaxDecimal;

/**
 * A received ADN NFS-e is a credit REVIEW CANDIDATE, never proof of a
 * legally admissible IBS/CBS credit, utilization, or tax liability.
 *
 * The caller must already have verified the active-company ownership and
 * received fiscal role. This component only reads an XML snapshot offline.
 */
final class IbsCbsReceivedEvidence
{
    /**
     * @return array{
     *   source_sha256:string,
     *   competence:?string,
     *   credit_eligibility:string,
     *   ibs:array{amount:?string,status:string},
     *   cbs:array{amount:?string,status:string}
     * }
     */
    public function fromAuthorizedXml(string $xml): array
    {
        $snapshot = (new AuthorizedFiscalSnapshotExtractor())->extract($xml);

        return [
            'source_sha256' => $snapshot['source_sha256'],
            'competence' => $snapshot['competence_date'],
            'credit_eligibility' => 'unverifiable',
            'ibs' => $this->documentedAmount($snapshot['ibs_cbs']['ibs_total']),
            'cbs' => $this->documentedAmount($snapshot['ibs_cbs']['cbs_total']),
        ];
    }

    /**
     * @return array{amount:?string,status:string}
     */
    private function documentedAmount(?string $amount): array
    {
        if ($amount === null || $amount === '') {
            return ['amount' => null, 'status' => 'not_reported'];
        }

        if (preg_match('/^\d{1,15}(?:\.\d{1,2})?$/D', $amount) !== 1) {
            return ['amount' => null, 'status' => 'invalid_amount'];
        }

        try {
            $cents = ExactTaxDecimal::cents($amount);
        } catch (\DomainException | \OverflowException) {
            return ['amount' => null, 'status' => 'invalid_amount'];
        }

        return [
            'amount' => ExactTaxDecimal::formatCents($cents),
            'status' => $cents === 0 ? 'reported_zero' : 'reported_amount',
        ];
    }
}
