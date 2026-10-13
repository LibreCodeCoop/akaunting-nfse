<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;

/**
 * The profile row lock ends before the external Vault/file write. Serialize
 * the entire lifecycle across separate MySQL connections for each company.
 */
final class NfseCompanyProfileOperationLock
{
    /** @var array<string,true> */
    private static array $active = [];

    /**
     * @template T
     * @param \Closure():T $operation
     * @return T
     */
    public function run(int $companyId, \Closure $operation): mixed
    {
        if ($companyId <= 0) {
            throw new \DomainException('Akaunting company is unavailable.');
        }

        $name = 'nfse-profile-' . $companyId;
        if (isset(self::$active[$name])) {
            throw new \DomainException('Another NFS-e profile operation is in progress for this company.');
        }

        self::$active[$name] = true;
        $acquired = false;

        try {
            $connection = DB::connection();

            if ($connection->getDriverName() === 'mysql') {
                // Laravel's selectOne() defaults to a read connection. Advisory
                // locks MUST live on the same writer server as profile updates.
                $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name], false);
                $acquired = (int) ($row->acquired ?? 0) === 1;

                if (!$acquired) {
                    throw new \DomainException('Another NFS-e profile operation is in progress for this company.');
                }
            }

            return $operation();
        } finally {
            try {
                if ($acquired) {
                    $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
                }
            } finally {
                unset(self::$active[$name]);
            }
        }
    }
}
