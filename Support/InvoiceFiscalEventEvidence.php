<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseEmissionAttempt;

/**
 * Append-only, operator-attested date of the service's taxable event.
 *
 * An Akaunting invoice issue date and the DPS competence are not evidence of
 * this event. The most recent attestation applies to future first POSTs only.
 */
final class InvoiceFiscalEventEvidence
{
    /** @return array{id:int,date:string,kind:string,reference:string,attested_at:string}|null */
    public function latest(int $companyId, int $invoiceId, string $groupKey): ?array
    {
        if ($companyId <= 0 || $invoiceId <= 0 || trim($groupKey) === '') {
            throw new \InvalidArgumentException('Fiscal event evidence requires a company, invoice and group.');
        }

        $activeCompany = function_exists('company_id') ? (int) company_id() : 0;
        if ($activeCompany > 0 && $activeCompany !== $companyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot read another company fiscal evidence.');
        }

        $row = DB::table('nfse_fiscal_event_evidence')
            ->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)
            ->where('fiscal_group_key', $groupKey)
            ->orderByDesc('id')
            ->first();

        if (!is_object($row)) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'date' => (string) $row->taxable_event_date,
            'kind' => (string) $row->evidence_kind,
            'reference' => (string) $row->source_reference,
            'attested_at' => (string) $row->attested_at,
        ];
    }

    public function dateForGroup(Invoice $invoice, ?string $groupKey): ?string
    {
        $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        $key = null;
        foreach ($groups as $group) {
            if ($groupKey !== null && $groupKey !== ''
                && hash_equals((string) ($group['key'] ?? ''), $groupKey)) {
                $key = (string) $group['key'];
                break;
            }
        }
        if ($key === null && ($groupKey === null || $groupKey === '') && count($groups) === 1) {
            $key = (string) ($groups[0]['key'] ?? '');
        }

        if ($key === null || $key === '') {
            return null;
        }

        return $this->latest(
            (int) ($invoice->company_id ?? 0),
            (int) ($invoice->id ?? 0),
            $key,
        )['date'] ?? null;
    }

    public function attest(Invoice $invoice, string $groupKey, string $date, string $reference, int $userId): void
    {
        $companyId = (int) ($invoice->company_id ?? 0);
        $invoiceId = (int) ($invoice->id ?? 0);
        $activeCompany = function_exists('company_id') ? (int) company_id() : 0;
        $reference = trim($reference);
        $dateValue = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($companyId <= 0 || $invoiceId <= 0 || $userId <= 0
            || $activeCompany <= 0 || $activeCompany !== $companyId
            || (string) ($invoice->type ?? '') !== 'invoice') {
            throw new \Illuminate\Auth\Access\AuthorizationException('Fiscal evidence must belong to the active invoice company.');
        }
        if ($dateValue === false || $dateValue->format('Y-m-d') !== $date
            || $reference === '' || mb_strlen($reference) > 255) {
            throw new \InvalidArgumentException('Valid fiscal event date and source reference are required.');
        }

        DB::transaction(function () use ($invoiceId, $companyId, $groupKey, $date, $reference, $userId): void {
            $document = DB::table('documents')
                ->where('id', $invoiceId)
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->lockForUpdate()
                ->first(['id']);

            if (!is_object($document)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Fiscal evidence invoice is unavailable.');
            }

            $freshInvoice = Invoice::query()
                ->whereKey($invoiceId)
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->first();

            $groups = $freshInvoice instanceof Invoice
                ? (new InvoiceFiscalContextResolver())->groups($freshInvoice) : [];
            $selected = false;
            foreach ($groups as $group) {
                if (hash_equals((string) ($group['key'] ?? ''), $groupKey)) {
                    $selected = true;
                    break;
                }
            }

            if (!$selected) {
                throw new \InvalidArgumentException('Fiscal group changed; refresh the invoice before attesting.');
            }

            // A pending or ambiguous first POST must be recovered with its
            // original evidence, never rejudged by a new attestation.
            $unresolved = NfseEmissionAttempt::query()
                ->where('company_id', $companyId)
                ->where('invoice_id', $invoiceId)
                ->whereIn('status', ['pending', 'ambiguous'])
                ->exists();

            if ($unresolved) {
                throw new \LogicException('Reconcile the unresolved DPS before changing its fiscal event evidence.');
            }

            DB::table('nfse_fiscal_event_evidence')->insert([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'fiscal_group_key' => $groupKey,
                'taxable_event_date' => $date,
                'evidence_kind' => 'operator_attestation',
                'source_reference' => $reference,
                'attested_by' => $userId,
                'attested_at' => now(),
            ]);
        });
    }
}
