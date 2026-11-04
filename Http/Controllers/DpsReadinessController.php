<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Application\DpsFiscalIssuer;
use Modules\Nfse\Application\DpsOfficialReadOnlyLookup;
use Modules\Nfse\Application\DpsOperationalReadiness;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyProfileCatalog;

/**
 * Two GET-only resources shared by the native API and the session UI.
 * Neither endpoint provisions a series, reserves a DPS or sends a fiscal POST.
 */
final class DpsReadinessController extends Controller
{
    public function index(DpsOperationalReadiness $readiness): JsonResponse
    {
        return response()->json(['data' => $readiness->inspect($this->selectedCompany())]);
    }

    public function lookup(
        Request $request,
        DpsOperationalReadiness $readiness,
        DpsOfficialReadOnlyLookup $lookup,
    ): JsonResponse {
        $companyId = $this->selectedCompany();
        $number = $request->query('number');
        if (!is_string($number) || preg_match('/^[1-9][0-9]{0,14}$/D', $number) !== 1) {
            return response()->json(['error' => 'invalid_dps_number'], 422);
        }

        $state = $readiness->inspect($companyId);
        if ($state['authority_present'] !== true || $state['local_present'] !== true
            || $state['profile_active'] !== true || !is_string($state['series'])) {
            return response()->json(['error' => 'dps_authority_unavailable'], 409);
        }
        // Prevent a GET endpoint from becoming a general fiscal-number
        // enumeration facility. Historical allocations need their own
        // authenticated invoice/attempt resources.
        if (!in_array($number, array_filter([
            $state['last_number'], $state['candidate_number'],
        ], 'is_string'), true)) {
            return response()->json(['error' => 'dps_number_outside_review_window'], 422);
        }

        $profile = NfseCompanyProfile::query()->where('company_id', $companyId)
            ->where('status', 'active')->first();
        if (!$profile instanceof NfseCompanyProfile) {
            return response()->json(['error' => 'fiscal_profile_not_available'], 409);
        }

        $fiscal = $profile->fiscal_parameters ?? [];
        $issuer = new DpsFiscalIssuer(
            (string) ($fiscal['municipio_ibge'] ?? ''),
            2,
            (string) ($profile->certificate_cnpj ?? ''),
        );
        $identifier = $issuer->municipality . '2' . $issuer->registration
            . $state['series'] . str_pad($number, 15, '0', STR_PAD_LEFT);
        $official = $lookup->check($companyId, $state['environment'] === 2, $identifier);

        return response()->json(['data' => [
            'company_id' => $companyId,
            'environment' => $state['environment'],
            'series' => $state['series'],
            'number' => $number,
            'official_state' => $official,
            'safe_to_emit' => false,
            'exclusive_series_attested' => false,
            'evidence_limit' => 'Lookup is not proof of unused numbers or exclusive series ownership.',
        ]]);
    }

    private function selectedCompany(): int
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $user = auth()->user();
        if ($companyId <= 0 || !$user instanceof User) {
            abort(403);
        }
        (new NfseCompanyProfileCatalog())->assertAccessible($user, $companyId);
        return $companyId;
    }
}
