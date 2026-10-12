<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Observed direction of a cooperative supply. This does not assert eligibility
 * for the art. 271 IBS/CBS zero-rate without other transaction evidence.
 */
enum CooperativeSupplyRelation: string
{
    case COOPERATIVE_TO_ASSOCIATE = 'cooperative_to_associate';
    case ASSOCIATE_TO_COOPERATIVE = 'associate_to_cooperative';
    case PARTICIPATING_COOPERATIVES = 'participating_cooperatives';
    case UNRELATED = 'unrelated';
}
