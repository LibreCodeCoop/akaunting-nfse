<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\TaxFiscalAssociation;

/**
 * Internal confirmation service; the caller must authorize its company.
 * No public write endpoint is exposed here (operator UI belongs to #431).
 */
final class TaxFiscalAssociationStore
{
    public function confirm(
        int $companyId,
        int $taxId,
        FiscalTaxIdentity $identity,
        FiscalTaxRole $role,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $sourceReference,
        int $confirmedBy,
    ): TaxFiscalAssociation {
        $sourceReference = trim($sourceReference);
        $this->validateConfirmation(
            $companyId, $taxId, $effectiveFrom, $effectiveUntil, $sourceReference, $confirmedBy,
        );

        return DB::transaction(function () use (
            $companyId, $taxId, $identity, $role, $effectiveFrom, $effectiveUntil, $sourceReference, $confirmedBy,
        ): TaxFiscalAssociation {
            $this->assertTaxBelongsToCompany($companyId, $taxId);
            $this->assertRevisionDoesNotOverlap($companyId, $taxId, $effectiveFrom, $effectiveUntil);

            // Append a confirmed revision: never rewrite older assignments,
            // accounting amounts, or already authorized fiscal artifacts.
            return TaxFiscalAssociation::query()->create([
                'company_id' => $companyId,
                'tax_id' => $taxId,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'fiscal_identity' => $identity->value,
                'fiscal_role' => $role->value,
                'confirmation_kind' => 'operator_confirmed',
                'source_reference' => $sourceReference,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
            ]);
        });
    }

    private function validateConfirmation(
        int $companyId,
        int $taxId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $sourceReference,
        int $confirmedBy,
    ): void {
        if ($companyId <= 0 || $taxId <= 0 || $confirmedBy <= 0) {
            throw new \InvalidArgumentException('Company, tax and confirming operator must have positive IDs.');
        }

        $this->validateDate($effectiveFrom);
        if ($effectiveUntil !== null) {
            $this->validateDate($effectiveUntil);
            if ($effectiveUntil < $effectiveFrom) {
                throw new \InvalidArgumentException('Fiscal association end cannot precede its start.');
            }
        }

        if ($sourceReference === '' || strlen($sourceReference) > 255) {
            throw new \InvalidArgumentException('An audit reference up to 255 characters is required.');
        }
    }

    private function assertTaxBelongsToCompany(int $companyId, int $taxId): void
    {
        // Hold a row lock during overlap validation. A bare tax_id without
        // company_id is not sufficient to establish ownership.
        $tax = DB::table('taxes')
            ->where('id', $taxId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id']);

        if ($tax === null) {
            throw new \DomainException('The tax does not belong to the confirmed company.');
        }
    }

    private function assertRevisionDoesNotOverlap(
        int $companyId,
        int $taxId,
        string $effectiveFrom,
        ?string $effectiveUntil,
    ): void {
        $overlap = TaxFiscalAssociation::query()
            ->where('company_id', $companyId)
            ->where('tax_id', $taxId)
            ->where('effective_from', '<=', $effectiveUntil ?? '9999-12-31')
            ->where(static function ($query) use ($effectiveFrom): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $effectiveFrom);
            })
            ->exists();

        if ($overlap) {
            throw new \DomainException('Overlapping fiscal association requires explicit revision review.');
        }
    }

    private function validateDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Association dates must be valid ISO calendar dates.');
        }
    }
}
