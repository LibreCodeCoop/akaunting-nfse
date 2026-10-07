<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use JsonException;
use RuntimeException;

/**
 * Resolves the installed module version from its Akaunting manifest.
 *
 * The release process owns the manifest version. Runtime consumers should use
 * this resolver instead of duplicating version strings in application code.
 */
final class ModuleVersion
{
    private const PACKAGE_NAME = 'librecodeoop/akaunting-nfse';

    private ?string $resolved = null;

    private readonly PackageVersionProvider $packageVersions;

    public function __construct(
        private readonly ?string $manifestPath = null,
        ?PackageVersionProvider $packageVersions = null,
    ) {
        $this->packageVersions = $packageVersions ?? new ComposerPackageVersionProvider();
    }

    public function get(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->manifestPath === null) {
            $composerVersion = $this->packageVersions->get(self::PACKAGE_NAME);

            if ($composerVersion !== null) {
                return $this->resolved = $composerVersion;
            }
        }

        $path = $this->manifestPath ?? dirname(__DIR__) . '/module.json';
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read module manifest: %s', $path));
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid module manifest JSON: %s', $path),
                previous: $exception,
            );
        }

        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;

        if (!is_string($version) || trim($version) === '') {
            throw new RuntimeException(sprintf('Module manifest has no valid version: %s', $path));
        }

        return $this->resolved = trim($version);
    }

}

