<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * The only supported source of company-specific fiscal parameters is #419.
 * Legacy nfse.* settings must never silently substitute for a missing profile.
 */
final class NfseCompanyFiscalSettings
{
    /** @return array<string,mixed> */
    public function all(int $companyId): array
    {
        if ($companyId <= 0) {
            throw new \DomainException('Fiscal operation requires a company owner.');
        }

        $profile = (new NfseCompanyProfileCatalog())->requireActive($companyId);
        $cnpj = trim((string) ($profile->certificate_cnpj ?? ''));

        if ($cnpj === '') {
            throw new \DomainException('NFS-e company certificate identity is missing.');
        }

        $parameters = $profile->fiscal_parameters ?? [];

        // The identity is certificate-owned, not a mutable fiscal parameter.
        $parameters['cnpj_prestador'] = $cnpj;

        return $parameters;
    }

    public function value(int $companyId, string $key, mixed $default = null): mixed
    {
        if (!str_starts_with($key, 'nfse.')) {
            throw new \InvalidArgumentException('Only NFS-e fiscal parameters are supported.');
        }

        $values = $this->all($companyId);
        $key = substr($key, 5);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }
}
