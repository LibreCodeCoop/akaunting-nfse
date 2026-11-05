<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
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
        $this->companyId();

        // Presentation only; company-specific tax facts come from JSON.
        return view('nfse::settings.tax-associations');
    }

    /** Catalog of existing accounting taxes and independently confirmed fiscal classifications. */
    public function catalog(): JsonResponse
    {
        $companyId = $this->companyId();
        $associations = TaxFiscalAssociation::query()
            ->where('company_id', $companyId)->orderBy('effective_from')->get();

        $taxes = DB::table('taxes')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'type', 'rate', 'enabled']);

        return response()->json(['data' => [
            'taxes' => $taxes->map(static function ($tax) use ($associations): array {
                $revisions = $associations->where('tax_id', $tax->id);
                return [
                    'id' => (int) $tax->id,
                    'name' => (string) $tax->name,
                    'type' => (string) $tax->type,
                    'rate' => (string) $tax->rate,
                    'enabled' => (bool) $tax->enabled,
                    'revisions' => $revisions->map(static fn (TaxFiscalAssociation $revision): array => [
                        'id' => (int) $revision->id,
                        'fiscal_identity' => $revision->fiscal_identity,
                        'fiscal_role' => $revision->fiscal_role,
                        'effective_from' => $revision->effective_from,
                        'effective_until' => $revision->effective_until,
                        'source_reference' => $revision->source_reference,
                        'confirmed_by' => (int) $revision->confirmed_by,
                        'confirmed_at' => $revision->confirmed_at?->toDateString(),
                        'type_mismatch' => ($tax->type === 'withholding')
                            !== ($revision->fiscal_role === 'withheld_by_payer'),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'identities' => array_column(FiscalTaxIdentity::cases(), 'value'),
            'roles' => array_column(FiscalTaxRole::cases(), 'value'),
        ]]);
    }

    public function store(Request $request, TaxFiscalAssociationStore $store): RedirectResponse|JsonResponse
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

        $alreadyConfirmed = $this->identicalRevisionExists($companyId, $actorId, $values);
        try {
            if (!$alreadyConfirmed) {
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
                return $request->expectsJson()
                    ? response()->json(['reason' => 'tax_association_conflict', 'message' => 'Fiscal association rejected.'], 409)
                    : redirect()->back()->withInput()->withErrors([
                        'tax_id' => $exception->getMessage(),
                    ]);
            }
            $alreadyConfirmed = true;
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'tax_id' => (int) $values['tax_id'],
                'status' => 'confirmed',
                'already_confirmed' => $alreadyConfirmed,
            ]], $alreadyConfirmed ? 200 : 201);
        }

        return redirect()->route('nfse.settings.tax-associations.index')
            ->with('success', 'Associação fiscal confirmada. Nenhum imposto ou documento financeiro foi alterado.');
    }

    public function revise(
        Request $request,
        TaxFiscalAssociationStore $store,
        int $associationId,
    ): RedirectResponse|JsonResponse {
        $companyId = $this->companyId();
        $actorId = (int) auth()->id();
        abort_if($actorId <= 0, 403, 'An authenticated confirming operator is required.');

        $values = $request->validate([
            'fiscal_identity' => ['required', Rule::in(array_column(FiscalTaxIdentity::cases(), 'value'))],
            'fiscal_role' => ['required', Rule::in(array_column(FiscalTaxRole::cases(), 'value'))],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'acknowledge' => ['required', 'accepted'],
        ]);

        try {
            $revision = $store->revise(
                $companyId,
                $associationId,
                FiscalTaxIdentity::from($values['fiscal_identity']),
                FiscalTaxRole::from($values['fiscal_role']),
                $values['effective_from'],
                $values['effective_until'] ?? null,
                $values['source_reference'],
                $actorId,
            );
        } catch (\DomainException|\InvalidArgumentException) {
            $message = 'Retificação fiscal rejeitada. Confira a empresa, a vigência e eventuais conflitos.';
            return $request->expectsJson()
                ? response()->json(['reason' => 'tax_association_conflict', 'message' => $message], 409)
                : redirect()->back()->withInput()->withErrors(['effective_from' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => (int) $revision->id,
                'status' => 'confirmed',
                'already_confirmed' => !$revision->wasRecentlyCreated,
            ]], $revision->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('nfse.settings.tax-associations.index')
            ->with('success', 'Associação fiscal retificada. O histórico e os documentos foram preservados.');
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
