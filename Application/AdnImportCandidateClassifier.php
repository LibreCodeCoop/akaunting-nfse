<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Classifies an authorized ADN document by the local company's fiscal role.
 *
 * Only explicit document identities in the authorized XML are used. Unknown or
 * malformed XML stays review-safe as "unknown" rather than being guessed.
 */
final class AdnImportCandidateClassifier
{
    /**
     * @return 'emitted'|'received'|'intermediated'|'event'|'unknown'
     */
    public function classify(
        ?string $xml,
        string $companyDocument,
        string $documentType,
        string $eventType = '',
    ): string {
        if ($eventType !== '' || str_contains(strtoupper($documentType), 'EVENT')) {
            return 'event';
        }

        if ($xml === null || trim($xml) === '') {
            return 'unknown';
        }

        $company = $this->normalizeDocument($companyDocument);

        if ($company === '') {
            return 'unknown';
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$dom->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS,
            )) {
                return 'unknown';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($dom);
        $provider = $this->firstDocument($xpath, [
            '//*[local-name()="DPS"]/*[local-name()="infDPS"]/*[local-name()="prest"]/*[local-name()="CNPJ"]',
            '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="CNPJ"]',
        ]);
        $taker = $this->firstDocument($xpath, [
            '//*[local-name()="DPS"]/*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="CNPJ"]',
            '//*[local-name()="DPS"]/*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="CPF"]',
        ]);
        $intermediary = $this->firstDocument($xpath, [
            '//*[local-name()="DPS"]/*[local-name()="infDPS"]/*[local-name()="interm"]/*[local-name()="CNPJ"]',
            '//*[local-name()="DPS"]/*[local-name()="infDPS"]/*[local-name()="interm"]/*[local-name()="CPF"]',
        ]);

        if ($provider !== '' && $provider === $company) {
            return 'emitted';
        }

        if ($taker !== '' && $taker === $company) {
            return 'received';
        }

        if ($intermediary !== '' && $intermediary === $company) {
            return 'intermediated';
        }

        return 'unknown';
    }

    /**
     * @param list<string> $expressions
     */
    private function firstDocument(\DOMXPath $xpath, array $expressions): string
    {
        foreach ($expressions as $expression) {
            $nodes = $xpath->query($expression);

            if ($nodes !== false && $nodes->length > 0) {
                $value = $this->normalizeDocument((string) $nodes->item(0)?->textContent);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function normalizeDocument(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
    }
}
