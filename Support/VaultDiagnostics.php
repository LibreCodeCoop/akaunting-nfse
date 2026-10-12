<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Read-only Vault diagnosis coordinator. Transport is isolated so the rules
 * can be tested with synthetic responses, without Laravel or a Vault.
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
        return VaultDiagnosticPolicy::result('unverified', 'not_checked', VaultDiagnosticPolicy::emptyCapabilities());
    }

    /**
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     * @return array{state:string,reason:string,capabilities:array<string,bool>}
     */
    public function check(array $config): array
    {
        $caps = VaultDiagnosticPolicy::emptyCapabilities();

        $configurationError = VaultDiagnosticPolicy::configurationError($config);
        if ($configurationError !== null) {
            $state = $configurationError === 'missing_configuration' ? 'unconfigured' : 'failed';

            return VaultDiagnosticPolicy::result($state, $configurationError, $caps);
        }

        $base = rtrim($config['addr'], '/');
        $mount = trim($config['mount'], '/');

        try {
            $health = $this->transport->request('GET', $base . '/v1/sys/health');
            $caps['network'] = true;

            // Standby nodes may forward subsequent API requests to the leader.
            // A sealed, uninitialized or DR-secondary server is not ready.
            if (!in_array($health['status'], [200, 429, 473], true)) {
                return VaultDiagnosticPolicy::result('failed', 'server_unavailable', $caps);
            }

            $token = $this->authenticatedToken($base, $config);
            if ($token === null) {
                return VaultDiagnosticPolicy::result('failed', 'authentication_failed', $caps);
            }

            $lookup = $this->transport->request('GET', $base . '/v1/auth/token/lookup-self', $token);
            if ($lookup['status'] !== 200) {
                return VaultDiagnosticPolicy::result('failed', 'authentication_failed', $caps);
            }
            $caps['authentication'] = true;

            $info = $this->transport->request('GET', $base . '/v1/sys/internal/ui/mounts/' . $mount, $token);
            if ($info['status'] !== 200 || !VaultDiagnosticPolicy::isKvV2($info['body'])) {
                return VaultDiagnosticPolicy::result('failed', 'kv_v2_unavailable', $caps);
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
                return VaultDiagnosticPolicy::result('failed', 'permission_check_failed', $caps);
            }

            $rights = VaultDiagnosticPolicy::assessCapabilities($response['body'], $path);
            if ($rights === null) {
                return VaultDiagnosticPolicy::result('failed', 'permission_check_failed', $caps);
            }

            $caps['certificate_read'] = $rights['read'];
            $caps['certificate_write'] = $rights['write'];

            if (!$caps['certificate_read'] || !$caps['certificate_write']) {
                return VaultDiagnosticPolicy::result('failed', 'insufficient_capabilities', $caps);
            }

            return VaultDiagnosticPolicy::result('operational', 'verified', $caps);
        } catch (\Throwable) {
            return VaultDiagnosticPolicy::result('failed', 'network_failure', $caps);
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

        $auth = $login['body']['auth'] ?? null;
        $token = $login['status'] === 200 && is_array($auth) ? ($auth['client_token'] ?? null) : null;

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
