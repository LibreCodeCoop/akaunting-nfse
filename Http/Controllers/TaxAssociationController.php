<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Models\TaxFiscalAssociation;
use Modules\Nfse\Support\TaxFiscalAssociationStore;

/**
 * An operator confirms existing Akaunting tax identities; this is not tax provisioning.
 * Company ownership, including that of the selected tax, is enforced server-side.
 */
final class TaxAssociationController extends Controller
{
    public function index(): View
    {
        $companyId = $this->companyId();

        return view('nfse::settings.tax-associations', [
            'taxes' => DB::table('taxes')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'rate', 'enabled']),
            'associationsByTax' => TaxFiscalAssociation::query()
                ->where('company_id', $companyId)
                ->orderBy('effective_from')
                ->get()
                ->groupBy('tax_id'),
            'identities' => FiscalTaxIdentity::cases(),
            'roles' => FiscalTaxRole::cases(),
        ]);
    }

    public function store(Request $request, TaxFiscalAssociationStore $store): RedirectResponse
    {
        $companyId = $this->companyId();
        $actorId = (int) auth()->id();
        abort_if($actorId <= 0, 403, 'An authenticated confirming operator is required.');

        $values = $request->validate([
            'tax_id' => ['required', 'integer', 'min:1'],
            'fiscal_identity' => ['required', Rule::in(array_column(FiscalTaxIdentity::cases(), 'value'))],
            'fiscal_role' => ['required', Rule::in(array_column(FiscalTaxRole::cases(), 'value'))],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'acknowledge' => ['required', 'accepted'],
        ]);
        $values['source_reference'] = trim($values['source_reference']);
        $values['effective_until'] ??= null;

        try {
            if (!$this->identicalRevisionExists($companyId, $actorId, $values)) {
                $store->confirm(
                    $companyId,
                    (int) $values['tax_id'],
                    FiscalTaxIdentity::from($values['fiscal_identity']),
                    FiscalTaxRole::from($values['fiscal_role']),
                    $values['effective_from'],
                    $values['effective_until'],
                    $values['source_reference'],
                    $actorId,
                );
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            // An identical concurrent submission may have won the row lock.
            if (!$this->identicalRevisionExists($companyId, $actorId, $values)) {
                return redirect()->back()->withInput()->withErrors([
                    'tax_id' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->route('nfse.settings.tax-associations.index')
            ->with('success', 'Associação fiscal confirmada. Nenhum imposto ou documento financeiro foi alterado.');
    }

    /**
     * An exact retry is idempotent. Different revisions, sources or actors must
     * go through the store's inclusive-window overlap validation.
     *
     * @param array<string, mixed> $values
     */
    private function identicalRevisionExists(int $companyId, int $actorId, array $values): bool
    {
        // An old confirmation cannot authorize a retry if ownership has changed.
        $stillOwned = DB::table('taxes')
            ->where('id', (int) $values['tax_id'])
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();
        if (!$stillOwned) {
            return false;
        }

        return TaxFiscalAssociation::query()
            ->where('company_id', $companyId)
            ->where('tax_id', (int) $values['tax_id'])
            ->whereDate('effective_from', $values['effective_from'])
            ->where('effective_until', $values['effective_until'])
            ->where('fiscal_identity', $values['fiscal_identity'])
            ->where('fiscal_role', $values['fiscal_role'])
            ->where('source_reference', $values['source_reference'])
            ->where('confirmation_kind', 'operator_confirmed')
            ->where('confirmed_by', $actorId)
            ->exists();
    }

    private function companyId(): int
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_if($companyId <= 0, 403, 'An active company is required for fiscal tax associations.');

        return $companyId;
    }
}
