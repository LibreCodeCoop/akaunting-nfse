<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Municipal item diagnostics must use the owning company's fiscal profile.
 * Missing profiles remain unverifiable, never filled by old nfse.* settings.
 */
final class ItemMunicipalCompanyContext
{
    /** @return array{municipio_ibge:string,sandbox_mode:bool} */
    public function forCompany(int $companyId): array
    {
        $unavailable = ['municipio_ibge' => '', 'sandbox_mode' => true];
        if ($companyId <= 0) {
            return $unavailable;
        }

        try {
            $fiscal = (new NfseCompanyFiscalSettings())->all($companyId);
        } catch (\DomainException) {
            return $unavailable;
        }

        return [
            'municipio_ibge' => trim((string) ($fiscal['municipio_ibge'] ?? '')),
            'sandbox_mode' => filter_var($fiscal['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}
