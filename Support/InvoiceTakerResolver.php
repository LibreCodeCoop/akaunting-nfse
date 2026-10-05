<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;

/**
 * Resolves the national-taker fields that are already available on Akaunting's
 * contact/document models.
 */
final class InvoiceTakerResolver
{
    public function document(Invoice $invoice): string
    {
        return $this->normalizeDocument(
            $this->contactOrInvoiceStringField(
                $invoice->contact,
                $invoice,
                ['tax_number'],
                ['contact_tax_number'],
            ),
        );
    }

    public function name(Invoice $invoice): string
    {
        return $this->contactOrInvoiceStringField(
            $invoice->contact,
            $invoice,
            ['name'],
            ['contact_name'],
        );
    }

    /**
     * @return array{codigo_municipio:string,cep:string,logradouro:string,numero:string,complemento:string,bairro:string,inscricao_municipal:string,telefone:string,email:string}
     */
    public function payload(?object $contact, ?object $invoice = null): array
    {
        $codigoMunicipio = $this->municipalityCode($contact, $invoice);
        $cep = $this->normalizeCep(
            $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['zip_code', 'cep'],
                ['contact_zip_code'],
            ),
        );

        $logradouro = '';
        $numero = '';
        $complemento = '';
        $bairro = '';

        if ($codigoMunicipio !== '' && $cep !== '') {
            $logradouro = $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['address', 'logradouro'],
                ['contact_address'],
            );
            $numero = $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['number', 'numero'],
                ['contact_number'],
            );
            $complemento = $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['complement', 'complemento'],
                ['contact_complement'],
            );
            $bairro = $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['district', 'bairro', 'neighborhood'],
                ['contact_district', 'contact_neighborhood'],
            );
        } else {
            $codigoMunicipio = '';
            $cep = '';
        }

        return [
            'codigo_municipio' => $codigoMunicipio,
            'cep' => $cep,
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => $complemento,
            'bairro' => $bairro,
            'inscricao_municipal' => $this->contactOrInvoiceStringField(
                $contact,
                $invoice,
                ['inscricao_municipal', 'municipal_registration', 'im'],
                ['contact_inscricao_municipal', 'contact_municipal_registration', 'contact_im'],
            ),
            'telefone' => $this->normalizePhone(
                $this->contactOrInvoiceStringField(
                    $contact,
                    $invoice,
                    ['phone', 'telefone'],
                    ['contact_phone'],
                ),
            ),
            'email' => $this->normalizeEmail(
                $this->contactOrInvoiceStringField(
                    $contact,
                    $invoice,
                    ['email'],
                    ['contact_email'],
                ),
            ),
        ];
    }

    public function normalizeDocument(?string $document): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) $document) ?: '');

        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^\d{11}$/', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    private function municipalityCode(?object $contact, ?object $invoice): string
    {
        $raw = $this->contactOrInvoiceStringField(
            $contact,
            $invoice,
            ['municipio_ibge', 'city_ibge', 'ibge_code', 'city_code', 'city'],
            ['contact_municipio_ibge', 'contact_city_ibge', 'contact_ibge_code', 'contact_city_code', 'contact_city'],
        );
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        return strlen($digits) === 7 ? $digits : '';
    }

    private function normalizeCep(string $cep): string
    {
        $digits = preg_replace('/\D+/', '', $cep) ?: '';

        return strlen($digits) === 8 ? $digits : '';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        return strlen($digits) >= 8 && strlen($digits) <= 13 ? $digits : '';
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = trim($email);

        if ($normalized === '') {
            return '';
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false ? $normalized : '';
    }

    /**
     * @param list<string> $contactFields
     * @param list<string> $invoiceFields
     */
    private function contactOrInvoiceStringField(
        ?object $contact,
        ?object $invoice,
        array $contactFields,
        array $invoiceFields = [],
    ): string {
        $contactValue = $this->objectStringField($contact, $contactFields);

        return $contactValue !== ''
            ? $contactValue
            : $this->objectStringField($invoice, $invoiceFields);
    }

    /**
     * @param list<string> $fields
     */
    private function objectStringField(?object $object, array $fields): string
    {
        if ($object === null) {
            return '';
        }

        foreach ($fields as $field) {
            if (!isset($object->{$field})) {
                continue;
            }

            return trim((string) $object->{$field});
        }

        return '';
    }
}
