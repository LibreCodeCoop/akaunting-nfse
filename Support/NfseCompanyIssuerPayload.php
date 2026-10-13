<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Application\IbsCbsPayloadResolver;
use Modules\Nfse\Application\IssqnPayloadResolver;

/**
 * Produces DPS facts only from the explicitly selected company fiscal profile.
 * Never performs a legacy nfse.* settings lookup.
 */
final class NfseCompanyIssuerPayload
{
    /** @param array<string,mixed> $fiscal */
    public function sandboxMode(array $fiscal): bool
    {
        $raw = $fiscal['sandbox_mode'] ?? true;

        // Unknown or absent values must not silently activate production.
        return !in_array($raw, [false, 0, '0', 'false'], true);
    }

    /**
     * @param array<string,mixed> $fiscal
     * @return array{telefone:string,email:string}
     */
    public function contact(array $fiscal, ?object $nativeCompany = null): array
    {
        $phone = trim((string) ($fiscal['prestador_telefone'] ?? ''));
        $email = trim((string) ($fiscal['prestador_email'] ?? ''));

        if ($phone === '' && $nativeCompany !== null) {
            $phone = trim((string) ($nativeCompany->phone ?? ''));
        }
        if ($email === '' && $nativeCompany !== null) {
            $email = trim((string) ($nativeCompany->email ?? ''));
        }

        return [
            'telefone' => preg_replace('/\D+/', '', $phone) ?: '',
            'email' => $email,
        ];
    }

    /**
     * @param array<string,mixed> $fiscal
     * @return array<string,mixed>
     */
    public function issqn(array $fiscal): array
    {
        return (new IssqnPayloadResolver())->resolve($fiscal);
    }

    /**
     * @param array<string,mixed> $fiscal
     * @return array<string,mixed>
     */
    public function ibsCbs(array $fiscal): array
    {
        return (new IbsCbsPayloadResolver())->resolve([
            'enabled' => $fiscal['ibs_cbs_enabled'] ?? false,
            'ind_final' => $fiscal['ibs_cbs_ind_final'] ?? '',
            'ind_dest' => $fiscal['ibs_cbs_ind_dest'] ?? '',
            'c_ind_op' => $fiscal['ibs_cbs_c_ind_op'] ?? '',
            'cst' => $fiscal['ibs_cbs_cst'] ?? '',
            'c_class_trib' => $fiscal['ibs_cbs_c_class_trib'] ?? '',
        ]);
    }
}
