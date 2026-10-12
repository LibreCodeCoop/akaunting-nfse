<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\TaxFiscalAssociation;

/**
 * Reads company-scoped, persisted Akaunting tax facts; never infers fiscal
 * identity from names, percentages, or the accounting tax type.
 */
final class InvoiceTaxFactsResolver
{
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
        $this->validateSelection($companyId, $invoiceId, $competenceDate, $documentItemIds);

        if ($documentItemIds === []) {
            return [];
        }

        $rows = $this->readActiveRows($companyId, $invoiceId, $documentItemIds);
        if ($rows->isEmpty()) {
            return [];
        }

        $associations = $this->confirmedAssociations($companyId, $competenceDate, $rows);
        $facts = [];
        foreach ($rows as $row) {
            $facts[] = $this->toFact($row, $associations->get((int) $row->tax_id));
        }

        return $facts;
    }

    /**
     * @param list<int>|null $documentItemIds
     */
    private function validateSelection(
        int $companyId,
        int $invoiceId,
        string $competenceDate,
        ?array $documentItemIds,
    ): void {
        if ($companyId <= 0 || $invoiceId <= 0) {
            throw new \InvalidArgumentException('Company and invoice IDs must be positive.');
        }

        $competence = \DateTimeImmutable::createFromFormat('!Y-m-d', $competenceDate);
        if ($competence === false || $competence->format('Y-m-d') !== $competenceDate) {
            throw new \InvalidArgumentException('A real ISO competence date is required.');
        }

        foreach ($documentItemIds ?? [] as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Selected document item IDs must be positive integers.');
            }
        }
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return Collection<int,\stdClass>
     */
    private function readActiveRows(int $companyId, int $invoiceId, ?array $documentItemIds): Collection
    {
        return DB::table('document_item_taxes as dit')
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
            ->whereNull('di.deleted_at')
            ->whereNull('dit.deleted_at')
            ->when($documentItemIds !== null, static function ($query) use ($documentItemIds): void {
                $query->whereIn('dit.document_item_id', $documentItemIds);
            })
            ->orderBy('dit.id')
            ->get([
                'dit.id as tax_row_id',
                'dit.document_item_id',
                'dit.tax_id',
                // SQLite may hydrate decimal columns as floats. Preserve the
                // persisted decimal representation before exact parsing.
                DB::raw('CAST(dit.amount AS CHAR) AS amount'),
                DB::raw('CAST(dit.rate AS CHAR) AS rate'),
                't.type as accounting_type',
            ]);
    }

    /**
     * @param Collection<int,\stdClass> $rows
     * @return Collection<int,TaxFiscalAssociation>
     */
    private function confirmedAssociations(int $companyId, string $date, Collection $rows): Collection
    {
        $taxIds = $rows->pluck('tax_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();
        $associations = TaxFiscalAssociation::query()
            ->where('company_id', $companyId)
            ->whereIn('tax_id', $taxIds)
            ->whereDate('effective_from', '<=', $date)
            ->where(static function ($query) use ($date): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date);
            })
            ->get();

        // Invalid direct writes must fail rather than select an arbitrary
        // fiscal identity for two concurrently effective revisions.
        if ($associations->count() !== $associations->unique('tax_id')->count()) {
            throw new \DomainException('Overlapping fiscal associations require explicit review.');
        }

        return $associations->keyBy('tax_id');
    }

    private function toFact(\stdClass $row, ?TaxFiscalAssociation $association): FiscalTaxFact
    {
        [$identity, $role] = $this->classification($association);

        return new FiscalTaxFact(
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

    /**
     * @return array{0:?string,1:?FiscalTaxRole}
     */
    private function classification(?TaxFiscalAssociation $association): array
    {
        if ($association === null) {
            return [null, null];
        }

        $identity = strtolower(trim((string) $association->fiscal_identity));
        $role = FiscalTaxRole::tryFrom((string) $association->fiscal_role);
        if (FiscalTaxIdentity::tryFrom($identity) === null
            || $role === null
            || trim((string) $association->source_reference) === ''
            || (int) $association->confirmed_by <= 0
            || $association->confirmed_at === null
            || !in_array((string) $association->confirmation_kind, ['operator_confirmed', 'reviewed_import'], true)) {
            throw new \DomainException('Invalid confirmed fiscal association; explicit review required.');
        }

        return [$identity, $role];
    }
}
