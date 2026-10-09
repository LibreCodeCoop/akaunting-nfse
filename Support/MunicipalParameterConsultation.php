<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * A completed municipal GET consultation and the metadata available from
 * its endpoint responses. A decoded response does not expose the HTTP status
 * in nfse-php: unknown is represented by null, never guessed as 200.
 */
final readonly class MunicipalParameterConsultation
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $provenance
     */
    public function __construct(
        public array $data,
        public array $provenance,
    ) {
    }
}
