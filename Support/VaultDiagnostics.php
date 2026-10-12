<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\Http;

/**
 * Explicit, read-only health/capability check. Never return response bodies,
 * exceptions, credentials or an untrusted server-provided error message.
 */
final class VaultDiagnostics
{
    /**
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     * @return array{state:string,reason:string,capabilities:array<string,bool>}
     */
    public function check(array $config): array
    {
        $caps = ['network' => false, 'authentication' => false, 'kv_v2' => false, 'certificate_read' => false, 'certificate_write' => false];
        if (!VaultConfig::configured($config)) {
            return ['state' => 'unconfigured', 'reason' => 'missing_configuration', 'capabilities' => $caps];
        }

        // No arbitrary redirects and no clear-text remote secrets. Address
        // validation is also repeated here for configurations from env/DB.
        if (!filter_var($config['addr'], FILTER_VALIDATE_URL) || !str_starts_with($config['addr'], 'https://')) {
            return ['state' => 'failed', 'reason' => 'invalid_endpoint', 'capabilities' => $caps];
        }

        $base = rtrim($config['addr'], '/');
        $mount = trim($config['mount'], '/');
        if (!preg_match('/^[a-zA-Z0-9_\/-]+$/', $mount) || str_contains($mount, '..')) {
            return ['state' => 'failed', 'reason' => 'invalid_mount', 'capabilities' => $caps];
        }

        try {
            $health = Http::timeout(5)->withoutRedirecting()->get($base . '/v1/sys/health');
            if ($health->status() >= 500) {
                return ['state' => 'failed', 'reason' => 'server_unavailable', 'capabilities' => $caps];
            }
            $caps['network'] = true;

            $token = $config['token'];
            if ($token === null) {
                $login = Http::timeout(5)->withoutRedirecting()->post($base . '/v1/auth/approle/login', [
                    'role_id' => $config['roleId'], 'secret_id' => $config['secretId'],
                ]);
                if (!$login->successful()) {
                    return ['state' => 'failed', 'reason' => 'authentication_failed', 'capabilities' => $caps];
                }
                $token = $login->json('auth.client_token');
            }

            if (!is_string($token) || $token === '') {
                return ['state' => 'failed', 'reason' => 'authentication_failed', 'capabilities' => $caps];
            }

            $client = Http::timeout(5)->withoutRedirecting()->withHeaders(['X-Vault-Token' => $token]);
            $lookup = $client->get($base . '/v1/auth/token/lookup-self');
            if (!$lookup->successful()) {
                return ['state' => 'failed', 'reason' => 'authentication_failed', 'capabilities' => $caps];
            }
            $caps['authentication'] = true;

            $mountInfo = $client->get($base . '/v1/sys/internal/ui/mounts/' . $mount);
            if (!$mountInfo->successful() || $mountInfo->json('data.type') !== 'kv'
                || (string) $mountInfo->json('data.options.version') !== '2') {
                return ['state' => 'failed', 'reason' => 'kv_v2_unavailable', 'capabilities' => $caps];
            }
            $caps['kv_v2'] = true;

            $path = $mount . '/data/pfx/__nfse_capability_probe__';
            $permissions = $client->post($base . '/v1/sys/capabilities-self', ['paths' => [$path]]);
            if (!$permissions->successful()) {
                return ['state' => 'failed', 'reason' => 'permission_check_failed', 'capabilities' => $caps];
            }

            $grants = $permissions->json('capabilities');
            $grants = is_array($grants) ? $grants : $permissions->json($path);
            if (!is_array($grants)) {
                $grants = [];
            }
            $caps['certificate_read'] = in_array('read', $grants, true) || in_array('root', $grants, true);
            $caps['certificate_write'] = in_array('create', $grants, true) && in_array('update', $grants, true)
                || in_array('root', $grants, true);

            if (!$caps['certificate_read'] || !$caps['certificate_write']) {
                return ['state' => 'failed', 'reason' => 'insufficient_capabilities', 'capabilities' => $caps];
            }

            return ['state' => 'operational', 'reason' => 'verified', 'capabilities' => $caps];
        } catch (\Throwable) {
            return ['state' => 'failed', 'reason' => 'network_failure', 'capabilities' => $caps];
        }
    }
}
