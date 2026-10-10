<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/ControllerIsolationState.php';
    require_once __DIR__ . '/Stubs/App/Models/Document/Document.php';
    require_once __DIR__ . '/Stubs/App/Models/Sale/Invoice.php';
    require_once __DIR__ . '/Stubs/App/Models/Sale/FakeCollection.php';
    require_once __DIR__ . '/Stubs/Modules/Nfse/Models/NfseReceipt.php';
    require_once __DIR__ . '/Stubs/App/Models/Setting/EmailTemplate.php';
    require_once __DIR__ . '/Stubs/Modules/Nfse/Application/EmissionAttemptJournal.php';
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers\Support {
    use App\Models\Sale\FakeCollection;
    use App\Models\Sale\Invoice;
    use App\Models\Setting\EmailTemplate;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Models\NfseReceipt;

    final class InvoiceControllerIsolationState
    {
        public static function reset(): void
        {
            ControllerIsolationState::reset();
            NfseReceipt::$records = [];
            NfseReceipt::$updateOrCreateCalls = [];
            NfseReceipt::$paginateItems = [];
            NfseReceipt::$payloads = [];
            EmailTemplate::$stubInstance = null;
        }

        /**
         * @param list<array{name: string}> $items
         */
        public static function makeInvoice(
            int $id,
            float $amount,
            array $items = [],
            string $description = '',
            ?string $contactName = null,
            ?string $contactTaxNumber = null,
            ?string $contactAddress = null,
            ?string $contactZipCode = null,
            ?string $contactCityIbge = null,
            ?string $contactPhone = null,
            ?string $contactEmail = null,
            ?string $issuedAt = '2026-09-30 12:00:00',
        ): Invoice {
            $contact = null;

            if ($contactName !== null || $contactTaxNumber !== null || $contactAddress !== null || $contactZipCode !== null || $contactCityIbge !== null || $contactPhone !== null || $contactEmail !== null) {
                $contact = (object) [
                    'name' => $contactName,
                    'tax_number' => $contactTaxNumber,
                    'address' => $contactAddress,
                    'zip_code' => $contactZipCode,
                    'municipio_ibge' => $contactCityIbge,
                    'phone' => $contactPhone,
                    'email' => $contactEmail,
                ];
            }

            $invoice = new Invoice(
                id: $id,
                amount: $amount,
                contact: $contact,
                items: new FakeCollection($items),
                description: $description,
            );
            $invoice->issued_at = $issuedAt;

            return $invoice;
        }

        public static function makeReceipt(int $invoiceId, string $chaveAcesso, string $status = 'emitted'): NfseReceipt
        {
            $receipt = new NfseReceipt();
            $receipt->id = count(NfseReceipt::$records) + 1;
            $receipt->invoice_id = $invoiceId;
            $receipt->chave_acesso = $chaveAcesso;
            $receipt->status = $status;

            NfseReceipt::$records[] = $receipt;

            return $receipt;
        }
    }
}
