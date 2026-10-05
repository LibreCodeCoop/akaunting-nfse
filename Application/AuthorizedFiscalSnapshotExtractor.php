<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Extracts an immutable, auditable fiscal snapshot from authorized NFS-e XML.
 *
 * The snapshot stores official raw values instead of recomputing tax semantics.
 * Missing optional groups remain null and can be surfaced as unresolved by
 * reconciliation/reporting instead of being guessed from the accounting invoice.
 */
final class AuthorizedFiscalSnapshotExtractor
{
    /**
     * @return array{
     *   schema_version:int,
     *   source:string,
     *   source_sha256:string,
     *   competence_date:?string,
     *   gross_service_value:?string,
     *   liquid_value:?string,
     *   issqn:array{
     *     taxation:?string,
     *     retention_type:?string,
     *     rate:?string,
     *     calculation_base:?string,
     *     value:?string
     *   },
     *   federal:array{
     *     pis:?string,
     *     cofins:?string,
     *     irrf:?string,
     *     social_security:?string,
     *     csll:?string
     *   },
     *   ibs_cbs:array{
     *     calculation_base:?string,
     *     ibs_total:?string,
     *     cbs_total:?string,
     *     nfse_total:?string
     *   }
     * }
     */
    public function extract(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new \InvalidArgumentException('DOCTYPE is not allowed in authorized NFS-e XML.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();

            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new \InvalidArgumentException('Authorized NFS-e XML is not well formed.');
            }

            $xpath = new \DOMXPath($document);

            return [
                'schema_version' => 1,
                'source' => 'authorized_xml',
                'source_sha256' => hash('sha256', $xml),
                'competence_date' => $this->value($xpath, "(//*[local-name()='infDPS']/*[local-name()='dCompet'])[1]"),
                'gross_service_value' => $this->value($xpath, "(//*[local-name()='infDPS']/*[local-name()='valores']/*[local-name()='vServPrest']/*[local-name()='vServ'])[1]"),
                'liquid_value' => $this->value($xpath, "(//*[local-name()='infNFSe']/*[local-name()='valores']/*[local-name()='vLiq'])[1]"),
                'issqn' => [
                    'taxation' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribMun']/*[local-name()='tribISSQN'])[1]"),
                    'retention_type' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribMun']/*[local-name()='tpRetISSQN'])[1]"),
                    'rate' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribMun']/*[local-name()='pAliq'])[1]"),
                    'calculation_base' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribMun']/*[local-name()='vBC'])[1]"),
                    'value' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribMun']/*[local-name()='vISSQN'])[1]"),
                ],
                'federal' => [
                    'pis' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribFed']//*[local-name()='vPis'])[1]"),
                    'cofins' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribFed']//*[local-name()='vCofins'])[1]"),
                    'irrf' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribFed']/*[local-name()='vRetIRRF'])[1]"),
                    'social_security' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribFed']/*[local-name()='vRetCP'])[1]"),
                    'csll' => $this->value($xpath, "(//*[local-name()='infDPS']//*[local-name()='tribFed']/*[local-name()='vRetCSLL'])[1]"),
                ],
                'ibs_cbs' => [
                    'calculation_base' => $this->value($xpath, "(//*[local-name()='infNFSe']/*[local-name()='IBSCBS']/*[local-name()='valores']/*[local-name()='vBC'])[1]"),
                    'ibs_total' => $this->value($xpath, "(//*[local-name()='infNFSe']/*[local-name()='IBSCBS']//*[local-name()='gIBS']/*[local-name()='vIBSTot'])[1]"),
                    'cbs_total' => $this->value($xpath, "(//*[local-name()='infNFSe']/*[local-name()='IBSCBS']//*[local-name()='gCBS']/*[local-name()='vCBS'])[1]"),
                    'nfse_total' => $this->value($xpath, "(//*[local-name()='infNFSe']/*[local-name()='IBSCBS']//*[local-name()='vTotNF'])[1]"),
                ],
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function value(\DOMXPath $xpath, string $expression): ?string
    {
        $value = trim((string) $xpath->evaluate('string(' . $expression . ')'));

        return $value !== '' ? $value : null;
    }
}
