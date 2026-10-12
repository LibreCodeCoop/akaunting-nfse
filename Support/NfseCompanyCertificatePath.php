<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Certificates belong to company_id and an immutable random revision, never
 * to CNPJ. Two Akaunting companies may legitimately share a fiscal CNPJ.
 */
final class NfseCompanyCertificatePath
{
    public static function newReference(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function vaultPath(int $companyId, string $reference): string
    {
        self::assertIdentifiers($companyId, $reference);

        return sprintf('pfx/companies/%d/%s', $companyId, $reference);
    }

    public static function filePath(int $companyId, string $reference): string
    {
        self::assertIdentifiers($companyId, $reference);

        return storage_path(sprintf('app/nfse/pfx/companies/%d/%s.pfx', $companyId, $reference));
    }

    private static function assertIdentifiers(int $companyId, string $reference): void
    {
        if ($companyId <= 0 || preg_match('/^[a-f0-9]{32}$/D', $reference) !== 1) {
            throw new \InvalidArgumentException('Invalid NFS-e company certificate identity.');
        }
    }
}
