<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;

/** Local E0322 preflight: an IBS/CBS DPS must contain an official NBS service code. */
final class NbsEmissionReadiness
{
    public function valid(string $codigoNbs, bool $ibsCbsEnabled): bool
    {
        if (!$ibsCbsEnabled) {
            return true;
        }

        $codigoNbs = trim($codigoNbs);

        return preg_match('/^\d{9}$/D', $codigoNbs) === 1
            && (new OfficialDomainCatalog())->hasNbs($codigoNbs);
    }
}
