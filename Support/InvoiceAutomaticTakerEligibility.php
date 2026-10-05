<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;

/**
 * Conservative automatic-emission guard for taker data.
 *
 * Foreign takers still require request-time NIF/address review in the manual
 * modal, so automatic/bulk emission must not guess or synthesize those fields.
 */
final class InvoiceAutomaticTakerEligibility
{
    /**
     * @return array{eligible:bool,reason:?string,details:list<string>}
     */
    public function evaluate(Invoice $invoice): array
    {
        $country = strtoupper($this->firstString(
            $invoice->contact ?? null,
            $invoice,
            ['country_code', 'country'],
            ['contact_country_code', 'contact_country'],
        ));

        if ($country !== '' && $country !== 'BR') {
            return [
                'eligible' => false,
                'reason' => 'foreign_taker_requires_review',
                'details' => [$country],
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'details' => [],
        ];
    }

    /**
     * @param list<string> $contactFields
     * @param list<string> $invoiceFields
     */
    private function firstString(
        ?object $contact,
        ?object $invoice,
        array $contactFields,
        array $invoiceFields,
    ): string {
        foreach ($contactFields as $field) {
            if ($contact !== null && isset($contact->{$field})) {
                $value = trim((string) $contact->{$field});

                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($invoiceFields as $field) {
            if ($invoice !== null && isset($invoice->{$field})) {
                $value = trim((string) $invoice->{$field});

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
