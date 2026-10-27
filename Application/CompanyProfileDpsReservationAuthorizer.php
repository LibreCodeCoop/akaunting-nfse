<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Auth\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Support\NfseCompanyProfileCatalog;

/**
 * Issuance authority derives from an owned invoice, selected Akaunting
 * company, active fiscal profile and explicit series authorization.
 */
final class CompanyProfileDpsReservationAuthorizer implements DpsReservationAuthorizer
{
    public function assertCanReserve(
        int $companyId,
        int $invoiceId,
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
    ): void {
        if ($companyId <= 0 || !function_exists('company_id')
            || (int) company_id() !== $companyId
            || !DB::table('documents')->where('id', $invoiceId)
                ->where('type', 'invoice')->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('Fiscal operation is not owned by the active company.');
        }

        $user = function_exists('auth') ? auth()->user() : null;
        if ($user instanceof User) {
            (new NfseCompanyProfileCatalog())->assertAccessible($user, $companyId);
            if (!$user->can('update-sales-invoices')) {
                throw new AuthorizationException('Fiscal issuance permission is required.');
            }
        } elseif (!app()->runningInConsole()) {
            throw new AuthorizationException('A verified fiscal actor is required.');
        }
        // Queue and CLI callers must have restored and validated the owning
        // company before invocation; the global company context is mandatory.

        $profile = (new NfseCompanyProfileCatalog())->requireActive($companyId);
        $fiscal = $profile->fiscal_parameters ?? [];
        $registered = new DpsFiscalIssuer(
            (string) ($fiscal['municipio_ibge'] ?? ''),
            2,
            (string) ($profile->certificate_cnpj ?? ''),
        );
        $sandbox = (new \Modules\Nfse\Support\NfseCompanyIssuerPayload())->sandboxMode($fiscal);
        $approvedSeries = DpsIssuanceIdentity::normalizeSeries($fiscal['dps_series'] ?? null);

        if ($registered->municipality !== $issuer->municipality
            || $registered->registrationType !== $issuer->registrationType
            || $registered->registration !== $issuer->registration
            || ($sandbox ? 2 : 1) !== $environment
            || $approvedSeries !== $series) {
            throw new AuthorizationException('Fiscal identity, environment or series is not authorized.');
        }
    }
}
