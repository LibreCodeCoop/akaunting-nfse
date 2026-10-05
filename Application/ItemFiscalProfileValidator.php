<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\Lc116Code;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;

/**
 * Validates item fiscal-profile fields only against authoritative datasets.
 *
 * The current Anexo VIII service/NBS/IBS-CBS correlation is advisory and is
 * deliberately reported as unverifiable instead of being enforced.
 */
final class ItemFiscalProfileValidator
{
    public function __construct(
        private readonly ?Lc116Catalog $lc116 = null,
        private readonly ?OfficialDomainCatalog $official = null,
    ) {
    }

    /**
     * @return array{
     *   status:'valid'|'warning'|'invalid'|'unverifiable',
     *   issues:list<string>,
     *   correlation_status:'unverifiable',
     *   source_versions:array<string,string>
     * }
     */
    public function validate(?string $serviceCode, ?string $nationalCode): array
    {
        $lc116 = $this->lc116 ?? new Lc116Catalog();
        $official = $this->official ?? new OfficialDomainCatalog();

        $service = Lc116Code::normalize($serviceCode);
        $national = preg_replace('/\D+/', '', (string) $nationalCode) ?: '';
        $issues = [];

        if ($service === '' && $national === '') {
            return $this->result('unverifiable', ['missing_profile'], $official);
        }

        if ($service === '') {
            $issues[] = 'missing_service_code';
        } elseif ($lc116->find($service) === null) {
            $issues[] = 'invalid_service_code';
        }

        if ($national === '') {
            $issues[] = 'missing_national_code';
        } elseif (strlen($national) !== 6 || !$official->hasNationalService($national)) {
            $issues[] = 'invalid_national_code';
        }

        $invalid = array_filter(
            $issues,
            static fn (string $issue): bool => str_starts_with($issue, 'invalid_'),
        );

        if ($invalid !== []) {
            return $this->result('invalid', $issues, $official);
        }

        if ($issues !== []) {
            return $this->result('warning', $issues, $official);
        }

        return $this->result('valid', [], $official);
    }

    /**
     * @param 'valid'|'warning'|'invalid'|'unverifiable' $status
     * @param list<string> $issues
     * @return array{
     *   status:'valid'|'warning'|'invalid'|'unverifiable',
     *   issues:list<string>,
     *   correlation_status:'unverifiable',
     *   source_versions:array<string,string>
     * }
     */
    private function result(string $status, array $issues, OfficialDomainCatalog $official): array
    {
        return [
            'status' => $status,
            'issues' => array_values($issues),
            'correlation_status' => 'unverifiable',
            'source_versions' => $official::sourceVersions(),
        ];
    }
}
