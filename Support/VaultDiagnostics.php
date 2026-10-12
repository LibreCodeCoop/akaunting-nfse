<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Side-effect-free Vault diagnostic policy. Transport is isolated so status
 * rules can be tested with synthetic responses, without Laravel or a Vault.
 *
 * No upstream body, exception or credential is ever part of a result.
 */
final class VaultDiagnostics
{
    private readonly VaultDiagnosticTransport $transport;

    public function __construct(?VaultDiagnosticTransport $transport = null)
    {
        $this->transport = $transport ?? new LaravelVaultDiagnosticTransport();
    }

    /**
     * For UI callers that have not explicitly requested a network check.
     *
     * @return array{state:string,reason:string,capabilities:array<string,bool>}
     */
    public function notVerified(): array
    {
        return self::result('unverified', 'not_checked', self::emptyCapabilities());
    }

    /**
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     * @return array{state:string,reason:string,capabilities:array<string,bool>}
     */
    public function check(array $config): array
    {
        $caps = self::emptyCapabilities();

        if (!VaultConfig::configured($config)) {
            return self::result('unconfigured', 'missing_configuration', $caps);
        }

        if (!self::validEndpoint($config['addr'])) {
            return self::result('failed', 'invalid_endpoint', $caps);
        }

        if (!self::validMount($config['mount'])) {
            return self::result('failed', 'invalid_mount', $caps);
        }

        $base = rtrim($config['addr'], '/');
        $mount = trim($config['mount'], '/');

        try {
            $health = $this->transport->request('GET', $base . '/v1/sys/health');
            $caps['network'] = true;

            // Standby nodes may forward subsequent API requests to the leader.
            // A sealed, uninitialized or DR-secondary server is not ready.
            if (!in_array($health['status'], [200, 429, 473], true)) {
                return self::result('failed', 'server_unavailable', $caps);
            }

            $token = $this->authenticatedToken($base, $config);
            if ($token === null) {
                return self::result('failed', 'authentication_failed', $caps);
            }

            $lookup = $this->transport->request('GET', $base . '/v1/auth/token/lookup-self', $token);
            if ($lookup['status'] !== 200) {
                return self::result('failed', 'authentication_failed', $caps);
            }
            $caps['authentication'] = true;

            $info = $this->transport->request('GET', $base . '/v1/sys/internal/ui/mounts/' . $mount, $token);
            if ($info['status'] !== 200 || !self::isKvV2($info['body'])) {
                return self::result('failed', 'kv_v2_unavailable', $caps);
            }
            $caps['kv_v2'] = true;

            $path = $mount . '/data/pfx/__nfse_capability_probe__';
            $response = $this->transport->request(
                'POST',
                $base . '/v1/sys/capabilities-self',
                $token,
                ['paths' => [$path]],
            );
            if ($response['status'] !== 200) {
                return self::result('failed', 'permission_check_failed', $caps);
            }

            $grants = self::grantsForPath($response['body'], $path);
            if ($grants === null) {
                return self::result('failed', 'permission_check_failed', $caps);
            }

            // Deny always wins, including over a malformed mixed grant.
            $allowed = !in_array('deny', $grants, true);
            $root = in_array('root', $grants, true);
            $caps['certificate_read'] = $allowed && ($root || in_array('read', $grants, true));
            $caps['certificate_write'] = $allowed && (
                $root || (in_array('create', $grants, true) && in_array('update', $grants, true))
            );

            if (!$caps['certificate_read'] || !$caps['certificate_write']) {
                return self::result('failed', 'insufficient_capabilities', $caps);
            }

            return self::result('operational', 'verified', $caps);
        } catch (\Throwable) {
            return self::result('failed', 'network_failure', $caps);
        }
    }

    /**
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     */
    private function authenticatedToken(string $base, array $config): ?string
    {
        if ($config['token'] !== null) {
            return trim($config['token']) !== '' ? $config['token'] : null;
        }

        $login = $this->transport->request('POST', $base . '/v1/auth/approle/login', null, [
            'role_id' => $config['roleId'],
            'secret_id' => $config['secretId'],
        ]);

        $token = $login['status'] === 200 ? ($login['body']['auth']['client_token'] ?? null) : null;

        return is_string($token) && trim($token) !== '' ? $token : null;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function isKvV2(array $body): bool
    {
        return ($body['data']['type'] ?? null) === 'kv'
            && (string) ($body['data']['options']['version'] ?? '') === '2';
    }

    /**
     * @param array<string,mixed> $body
     * @return null|list<string>
     */
    private static function grantsForPath(array $body, string $path): ?array
    {
        // Vault returns both a path-specific key and the backwards-compatible
        // "capabilities" key for a single queried path.
        $grants = $body[$path] ?? $body['data'][$path] ?? $body['capabilities'] ?? null;

        if (!is_array($grants) || !array_is_list($grants)) {
            return null;
        }

        foreach ($grants as $grant) {
            if (!is_string($grant)) {
                return null;
            }
        }

        return $grants;
    }

    private static function validEndpoint(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    private static function validMount(string $mount): bool
    {
        $mount = trim($mount, '/');

        return $mount !== ''
            && preg_match('/^[a-zA-Z0-9_\/-]+$/', $mount) === 1
            && !in_array('.', explode('/', $mount), true)
            && !in_array('..', explode('/', $mount), true)
            && !str_contains($mount, '//');
    }

    /** @return array<string,bool> */
    private static function emptyCapabilities(): array
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
    private static function result(string $state, string $reason, array $capabilities): array
    {
        return ['state' => $state, 'reason' => $reason, 'capabilities' => $capabilities];
    }
}
