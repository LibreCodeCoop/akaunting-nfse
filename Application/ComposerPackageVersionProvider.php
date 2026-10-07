<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class ComposerPackageVersionProvider implements PackageVersionProvider
{
    public function get(string $packageName): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        if (!\Composer\InstalledVersions::isInstalled($packageName)) {
            return null;
        }

        $version = \Composer\InstalledVersions::getPrettyVersion($packageName);

        if (!is_string($version) || trim($version) === '') {
            return null;
        }

        return ltrim(trim($version), 'v');
    }
}
