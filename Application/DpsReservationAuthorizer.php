<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Mandatory bridge to the company/issuer authorization contract (#417/#421).
 *
 * Implementations must verify the actual caller context as well as the
 * persisted fiscal profile's ownership. A company_id supplied by a request
 * or job is not itself proof of authorization. This interface deliberately
 * does not copy fiscal profiles, certificates or Vault readiness.
 */
interface DpsReservationAuthorizer
{
    public function assertCanReserve(int $companyId, int $invoiceId, DpsFiscalIssuer $issuer): void;
}
