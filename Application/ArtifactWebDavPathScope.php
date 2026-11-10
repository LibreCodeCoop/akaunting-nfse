<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/** Default to tenant-specific folders; allow explicitly configured dedicated roots. */
final class ArtifactWebDavPathScope
{
    public const COMPANY = 'company';

    public const CONFIGURED = 'configured';

    public function apply(string $basePath, int $companyId, string $scope): string
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('A valid company is required for WebDAV paths.');
        }

        $path = trim($basePath, '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new \InvalidArgumentException('A nonempty safe WebDAV path is required.');
        }

        // Unrecognized values preserve tenant isolation by default.
        return $scope === self::CONFIGURED ? $path : 'companies/' . $companyId . '/' . $path;
    }
}
