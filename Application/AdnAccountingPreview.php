<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class AdnAccountingPreview
{
    /**
     * @return array{
     *   supplier_name:string,
     *   supplier_tax_number:string,
     *   competence:string,
     *   service_description:string,
     *   gross_value:string,
     *   liquid_value:string,
     *   issqn_value:string,
     *   pis_value:string,
     *   cofins_value:string,
     *   irrf_value:string,
     *   csll_value:string
     * }
     */
    public function fromAuthorizedXml(string $xml): array
    {
        $document = new \DOMDocument();

        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \InvalidArgumentException('Authorized NFS-e XML is invalid.');
        }

        $xpath = new \DOMXPath($document);

        return [
            'supplier_name' => $this->first($xpath, [
                '//*[local-name()="prest"]//*[local-name()="xNome"]',
                '//*[local-name()="emit"]//*[local-name()="xNome"]',
            ]),
            'supplier_tax_number' => $this->digits($this->first($xpath, [
                '//*[local-name()="prest"]//*[local-name()="CNPJ"]',
                '//*[local-name()="prest"]//*[local-name()="CPF"]',
                '//*[local-name()="emit"]//*[local-name()="CNPJ"]',
                '//*[local-name()="emit"]//*[local-name()="CPF"]',
            ])),
            'competence' => $this->first($xpath, ['//*[local-name()="dCompet"]']),
            'service_description' => $this->first($xpath, [
                '//*[local-name()="xDescServ"]',
                '//*[local-name()="xTribNac"]',
            ]),
            'gross_value' => $this->decimal($this->first($xpath, [
                '//*[local-name()="vServPrest"]/*[local-name()="vServ"]',
                '//*[local-name()="vServ"]',
            ])),
            'liquid_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vLiq"]'])),
            'issqn_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vISSQN"]'])),
            'pis_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vPis"]'])),
            'cofins_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vCofins"]'])),
            'irrf_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vIRRF"]'])),
            'csll_value' => $this->decimal($this->first($xpath, ['//*[local-name()="vCSLL"]'])),
        ];
    }

    /**
     * @param list<string> $queries
     */
    private function first(\DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = trim((string) $nodes->item(0)?->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function decimal(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return '';
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
