<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/** Stateless business rules for Vault diagnostic states and ACL capabilities. */
final class VaultDiagnosticPolicy
{
    /**
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     */
    public static function configurationError(array $config): ?string
    {
        if (!VaultConfig::configured($config)) {
            return 'missing_configuration';
        }

        $endpoint = $config['addr'];
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || parse_url($endpoint, PHP_URL_USER) !== null
            || parse_url($endpoint, PHP_URL_PASS) !== null
            || parse_url($endpoint, PHP_URL_QUERY) !== null
            || parse_url($endpoint, PHP_URL_FRAGMENT) !== null
        ) {
            return 'invalid_endpoint';
        }

        $mount = trim($config['mount'], '/');
        if ($mount === '' || preg_match('/^[a-zA-Z0-9_\/-]+$/', $mount) !== 1
            || in_array('.', explode('/', $mount), true)
            || in_array('..', explode('/', $mount), true)
            || str_contains($mount, '//')
        ) {
            return 'invalid_mount';
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    public static function isKvV2(array $body): bool
    {
        return ($body['data']['type'] ?? null) === 'kv'
            && (string) ($body['data']['options']['version'] ?? '') === '2';
    }

    /**
     * @param array<string,mixed> $body
     * @return null|array{read:bool,write:bool}
     */
    public static function assessCapabilities(array $body, string $path): ?array
    {
        // Single-path requests may include a backwards-compatible "capabilities"
        // list alongside the path-specific list. Prefer the latter if present.
        $grants = $body[$path] ?? $body['data'][$path] ?? $body['capabilities'] ?? null;
        if (!is_array($grants) || !array_is_list($grants)) {
            return null;
        }

        foreach ($grants as $grant) {
            if (!is_string($grant)) {
                return null;
            }
        }

        // The deny capability overrides all positive rights.
        $allowed = !in_array('deny', $grants, true);
        $root = in_array('root', $grants, true);

        return [
            'read' => $allowed && ($root || in_array('read', $grants, true)),
            'write' => $allowed && (
                $root || (in_array('create', $grants, true) && in_array('update', $grants, true))
            ),
        ];
    }

    /**
     * KV v2 delete is a separate capability from create/update. Deny wins,
     * even if the server includes root in the response.
     *
     * @param array<string,mixed> $body
     */
    public static function allowsDelete(array $body, string $path): bool
    {
        $grants = $body[$path] ?? $body['data'][$path] ?? $body['capabilities'] ?? null;

        if (!is_array($grants) || !array_is_list($grants)) {
            return false;
        }

        foreach ($grants as $grant) {
            if (!is_string($grant)) {
                return false;
            }
        }

        return !in_array('deny', $grants, true)
            && (in_array('delete', $grants, true) || in_array('root', $grants, true));
    }

    /** @return array<string,bool> */
    public static function emptyCapabilities(): array
    {
        return [
            'network' => false,
            'authentication' => false,
            'kv_v2' => false,
            'certificate_read' => false,
            'certificate_write' => false,
        ];
    }

    /**
     * @param array<string,bool> $capabilities
     * @return array{state:string,reason:string,capabilities:array<string,bool>}
     */
    public static function result(string $state, string $reason, array $capabilities): array
    {
        return ['state' => $state, 'reason' => $reason, 'capabilities' => $capabilities];
    }
}
