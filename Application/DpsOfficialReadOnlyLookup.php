<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Support\FiscalClientFactory;

/**
 * Official DPS existence/key recovery without POST, journal changes or
 * releasing an allocation. The key itself is not returned to API consumers.
 */
final class DpsOfficialReadOnlyLookup
{
    /** @param (\Closure(int,bool):FiscalClientContext)|null $openContext */
    public function __construct(private readonly ?\Closure $openContext = null)
    {
    }

    public function check(int $companyId, bool $sandbox, string $identifier): string
    {
        $context = null;
        $result = 'inconclusive';
        try {
            $context = $this->openContext !== null
                ? ($this->openContext)($companyId, $sandbox)
                : app(FiscalClientFactory::class)->nfse($sandbox, $companyId);
            $client = $context->nfseClient();
            if (!is_callable([$client, 'existsDps']) || !is_callable([$client, 'queryDps'])) {
                throw new \LogicException('DPS lookup is not supported by the fiscal client');
            }
            $exists = $client->existsDps($identifier);
            if ($exists === false) {
                $result = 'not_found';
            } elseif ($exists === true) {
                $key = $client->queryDps($identifier);
                $result = is_string($key) && trim($key) !== '' ? 'found' : 'inconclusive';
            }
        } catch (\Throwable) {
            $result = 'inconclusive';
        } finally {
            if ($context !== null) {
                try {
                    $context->close();
                } catch (\Throwable) {
                    // Never claim a conclusive result when transport key cleanup failed.
                    $result = 'inconclusive';
                }
            }
        }

        return $result;
    }
}
