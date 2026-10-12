<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * A deterministic fiscal preflight veto, distinct from issuer rejection,
 * transport uncertainty and an unexpected programming failure.
 */
final class MunicipalIssuanceBlocked extends \LogicException
{
}
