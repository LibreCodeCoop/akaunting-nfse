<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Global, encrypted Vault infrastructure configuration. The single row has no
 * company_id and is never read through Akaunting's company-scoped setting().
 *
 * Stored configuration replaces the entire environment configuration, not
 * individual values, to avoid mixing credentials from distinct Vaults.
 */
final class GlobalVaultSettings
{
    private const TABLE = 'nfse_vault_infrastructure';

    /**
     * @return null|array{addr:string,mount:string,auth_mode:string,token:?string,roleId:?string,secretId:?string}
     */
    public function stored(): ?array
    {
        $ciphertext = DB::table(self::TABLE)->where('id', 1)->value('encrypted_payload');

        if ($ciphertext === null) {
            return null;
        }

        if (!is_string($ciphertext)) {
            throw new VaultConfigurationUnreadable('Invalid global Vault configuration.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new VaultConfigurationUnreadable('Cannot decrypt global Vault configuration.');
        }

        if (!is_array($decoded)
            || !isset($decoded['addr'], $decoded['mount'], $decoded['auth_mode'])
            || !in_array($decoded['auth_mode'], ['token', 'approle'], true)
        ) {
            throw new VaultConfigurationUnreadable('Invalid global Vault configuration.');
        }

        return [
            'addr' => (string) $decoded['addr'],
            'mount' => (string) $decoded['mount'],
            'auth_mode' => (string) $decoded['auth_mode'],
            'token' => isset($decoded['token']) ? (string) $decoded['token'] : null,
            'roleId' => isset($decoded['roleId']) ? (string) $decoded['roleId'] : null,
            'secretId' => isset($decoded['secretId']) ? (string) $decoded['secretId'] : null,
        ];
    }

    /**
     * Same resolved contract for HTTP, CLI and queue processes.
     *
     * @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string}
     */
    public function effective(): array
    {
        $stored = $this->stored();

        if ($stored === null) {
            return VaultConfig::environmentConfig();
        }

        return [
            'addr' => $stored['addr'],
            'mount' => $stored['mount'],
            'token' => $stored['auth_mode'] === 'token' ? $stored['token'] : null,
            'roleId' => $stored['auth_mode'] === 'approle' ? $stored['roleId'] : null,
            'secretId' => $stored['auth_mode'] === 'approle' ? $stored['secretId'] : null,
        ];
    }

    /**
     * Only the selected auth mode is persisted. Missing secrets preserve the
     * previous encrypted value for that mode; explicit null clears it.
     *
     * @param array{addr:string,mount:string,auth_mode:string,token?:?string,roleId?:?string,secretId?:?string} $input
     */
    public function save(array $input): void
    {
        $mode = $input['auth_mode'];
        if (!in_array($mode, ['token', 'approle'], true)) {
            throw new \InvalidArgumentException('Invalid Vault authentication mode.');
        }

        // A damaged encrypted record must be replaceable with complete fresh
        // credentials. Never recover by mixing environment or legacy secrets.
        try {
            $previous = $this->stored();
        } catch (VaultConfigurationUnreadable) {
            $previous = null;
        }
        $sameMode = ($previous['auth_mode'] ?? null) === $mode;
        $value = static function (string $key) use ($input, $previous, $sameMode): ?string {
            if (array_key_exists($key, $input)) {
                $raw = $input[$key];

                return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }

            return $sameMode ? ($previous[$key] ?? null) : null;
        };

        $config = [
            'addr' => trim($input['addr']),
            'mount' => VaultConfig::normalizeMount($input['mount']),
            'auth_mode' => $mode,
            'token' => $mode === 'token' ? $value('token') : null,
            'roleId' => $mode === 'approle' ? $value('roleId') : null,
            'secretId' => $mode === 'approle' ? $value('secretId') : null,
        ];

        // An operator can explicitly clear an existing credential to disable
        // Vault, but an absent secret must never silently configure a new
        // endpoint or authentication mode. Omitted secrets still mean "keep".
        $clearedToken = $sameMode && array_key_exists('token', $input) && $input['token'] === null;
        $clearedAppRole = $sameMode && (
            (array_key_exists('roleId', $input) && $input['roleId'] === null)
            || (array_key_exists('secretId', $input) && $input['secretId'] === null)
        );

        if ($config['addr'] === ''
            || ($mode === 'token' && $config['token'] === null && !$clearedToken)
            || ($mode === 'approle'
                && ($config['roleId'] === null || $config['secretId'] === null)
                && !$clearedAppRole)
        ) {
            throw new \InvalidArgumentException('Vault infrastructure configuration is incomplete.');
        }

        $ciphertext = Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR));
        $now = now();

        DB::table(self::TABLE)->updateOrInsert(['id' => 1], [
            'encrypted_payload' => $ciphertext,
            'updated_at' => $now,
        ]);
    }
}
