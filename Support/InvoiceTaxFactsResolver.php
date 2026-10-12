<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\TaxFiscalAssociation;

/**
 * Reads persisted Akaunting tax rows once, with an explicit tenant and date.
 *
 * Never uses tax names/rates to infer fiscal identity, role or incidence.
 * Invoices already authorized must use their immutable fiscal snapshot.
 */
final class InvoiceTaxFactsResolver
{
    /** @var list<string> */
    private const FISCAL_IDENTITIES = [
        'issqn', 'pis', 'cofins', 'csll', 'irrf', 'cp', 'ibs', 'cbs',
    ];

    /**
     * @param list<int>|null $documentItemIds null = all, [] = none
     * @return list<FiscalTaxFact>
     */
    public function resolve(
        int $companyId,
        int $invoiceId,
        string $competenceDate,
        ?array $documentItemIds = null,
    ): array {
        if ($companyId <= 0 || $invoiceId <= 0) {
            throw new \InvalidArgumentException('Company and invoice IDs must be positive.');
        }

        $competence = \DateTimeImmutable::createFromFormat('!Y-m-d', $competenceDate);
        if ($competence === false || $competence->format('Y-m-d') !== $competenceDate) {
            throw new \InvalidArgumentException('A real ISO competence date is required.');
        }

        if ($documentItemIds === []) {
            return [];
        }

        if ($documentItemIds !== null) {
            foreach ($documentItemIds as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new \InvalidArgumentException('Selected document item IDs must be positive integers.');
                }
            }
        }

        $rows = DB::table('document_item_taxes as dit')
            ->join('documents as d', static function (JoinClause $join): void {
                $join->on('d.id', '=', 'dit.document_id')
                    ->on('d.company_id', '=', 'dit.company_id');
            })
            ->join('document_items as di', static function (JoinClause $join): void {
                $join->on('di.id', '=', 'dit.document_item_id')
                    ->on('di.document_id', '=', 'dit.document_id')
                    ->on('di.company_id', '=', 'dit.company_id');
            })
            ->join('taxes as t', static function (JoinClause $join): void {
                $join->on('t.id', '=', 'dit.tax_id')
                    ->on('t.company_id', '=', 'dit.company_id');
            })
            ->where('dit.company_id', $companyId)
            ->where('dit.document_id', $invoiceId)
            ->where('dit.type', 'invoice')
            ->where('d.type', 'invoice')
            ->whereNull('dit.deleted_at')
            ->whereNull('t.deleted_at')
            ->when($documentItemIds !== null, static function ($query) use ($documentItemIds): void {
                $query->whereIn('dit.document_item_id', $documentItemIds);
            })
            ->orderBy('dit.id')
            ->get([
                'dit.id as tax_row_id',
                'dit.document_item_id',
                'dit.tax_id',
                'dit.amount',
                'dit.rate',
                't.type as accounting_type',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $taxIds = $rows->pluck('tax_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();
        $associations = TaxFiscalAssociation::query()
            ->where('company_id', $companyId)
            ->whereIn('tax_id', $taxIds)
            ->whereDate('effective_from', '<=', $competenceDate)
            ->where(static function ($query) use ($competenceDate): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $competenceDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('tax_id')
            ->keyBy('tax_id');

        $facts = [];
        foreach ($rows as $row) {
            $association = $associations->get((int) $row->tax_id);
            $identity = null;
            $role = null;

            if ($association !== null) {
                $identity = strtolower(trim((string) $association->fiscal_identity));
                $role = FiscalTaxRole::tryFrom((string) $association->fiscal_role);
                if (!in_array($identity, self::FISCAL_IDENTITIES, true)
                    || $role === null
                    || trim((string) $association->source_reference) === ''
                    || !in_array((string) $association->confirmation_kind, ['operator_confirmed', 'reviewed_import'], true)) {
                    throw new \DomainException('Invalid confirmed fiscal association; explicit review required.');
                }
            }

            $facts[] = new FiscalTaxFact(
                documentItemId: (int) $row->document_item_id,
                taxId: (int) $row->tax_id,
                documentItemTaxRowId: (int) $row->tax_row_id,
                accountingType: (string) $row->accounting_type,
                amountCents: ExactTaxDecimal::cents($row->amount),
                fiscalTaxCode: $identity,
                fiscalRole: $role,
                origin: 'akaunting.document_item_taxes',
                baseCents: null,
                ratePercent: ExactTaxDecimal::rate($row->rate),
            );
        }

        return $facts;
    }
}
