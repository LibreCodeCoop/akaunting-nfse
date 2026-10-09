<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Offline, read-only verification of the deployed DANFSe runtime.
 *
 * This does not load Akaunting, connect to its database or issue fiscal notes.
 * It cannot inspect OPcache held by a separately running PHP-FPM/queue worker.
 */
$root = dirname(__DIR__);
$manifestPath = $root . '/3rdparty/composer.json';
$lockPath = $root . '/3rdparty/composer.lock';
$autoloadPath = $root . '/3rdparty/scoped/autoload.php';

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $constraint = $manifest['require']['librecodeoop/nfse-php'] ?? '';
    if (!is_string($constraint) || preg_match('/^dev-main#([0-9a-f]{40})$/', $constraint, $matches) !== 1) {
        throw new RuntimeException('No pinned nfse-php revision in 3rdparty/composer.json.');
    }
    $expected = $matches[1];

    if (!is_file($lockPath)) {
        throw new RuntimeException('Missing local 3rdparty/composer.lock: run the runtime build.');
    }

    $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
    $installed = null;
    foreach (($lock['packages'] ?? []) as $package) {
        if (($package['name'] ?? null) === 'librecodeoop/nfse-php') {
            $installed = $package['source']['reference'] ?? null;
            break;
        }
    }

    if (!is_string($installed) || !hash_equals($expected, $installed)) {
        throw new RuntimeException(
            'Outdated nfse-php lock: expected ' . $expected . ', installed ' . ($installed ?? 'missing')
        );
    }

    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Missing scoped autoloader; rebuild 3rdparty/scoped.');
    }
    require_once $autoloadPath;

    $prefix = 'Modules\\Nfse\\Vendor\\LibreCodeCoop\\NfsePHP\\Danfse\\';
    $templateFile = (new ReflectionClass($prefix . 'DanfseTemplate'))->getFileName();
    $configFile = (new ReflectionClass($prefix . 'Config\\DanfseConfig'))->getFileName();
    if (!is_string($templateFile) || !is_string($configFile)) {
        throw new RuntimeException('Unable to resolve deployed DANFSe classes.');
    }

    $template = (string) file_get_contents(dirname($templateFile) . '/HTMLTemplate.php');
    if (!str_contains($template, 'PRESTADOR / FORNECEDOR')
        || !str_contains($template, 'TRIBUTAÇÃO MUNICIPAL (ISSQN)')) {
        throw new RuntimeException('Old DANFSe HTMLTemplate detected under 3rdparty/scoped.');
    }

    $logo = dirname($configFile) . '/../Assets/nfse-horizontal.png';
    if (!is_file($logo) || !is_readable($logo) || getimagesize($logo) === false) {
        throw new RuntimeException('Official PNG is missing from the scoped runtime: ' . $logo);
    }

    fwrite(STDOUT, "nfse-php commit: {$installed}\n");
    fwrite(STDOUT, "DANFSe class: {$templateFile}\n");
    fwrite(STDOUT, "DANFSe HTML: " . dirname($templateFile) . "/HTMLTemplate.php\n");
    fwrite(STDOUT, "NFS-e logo PNG: {$logo}\n");
    fwrite(STDOUT, "STATUS: runtime files match the pinned national-style DANFSe\n");
    fwrite(STDOUT, "IMPORTANT: Restart PHP-FPM and queue workers to invalidate in-memory OPcache.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "DANFSe runtime mismatch: {$error->getMessage()}\n");
    exit(1);
}
