<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Common\Company;
use Closure;

/**
 * Switch a queued fiscal operation to its independently verified Akaunting owner.
 * The caller must derive the ID from persistent records, never from the worker
 * session or an untrusted job argument alone.
 */
final class NfseCompanyJobContext
{
    public function run(int $companyId, Closure $action): mixed
    {
        if ($companyId <= 0) {
            throw new \DomainException('Fiscal job has no verified company owner.');
        }

        $previous = Company::getCurrent();
        if ((int) ($previous?->id ?? 0) === $companyId) {
            return $action();
        }

        $owner = Company::query()->findOrFail($companyId);
        $owner->makeCurrent();

        try {
            return $action();
        } finally {
            if ($previous instanceof Company) {
                $previous->makeCurrent();
            } else {
                Company::forgetCurrent();
            }
        }
    }
}
