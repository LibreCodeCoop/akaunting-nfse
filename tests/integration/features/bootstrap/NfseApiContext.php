<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Behat\Step\Then;

/**
 * Fiscal-domain assertions. Generic HTTP transport and JSON steps remain in
 * AkauntingHttpContext for reuse by other Akaunting modules.
 */
final class NfseApiContext extends AkauntingHttpContext
{
    #[Then('the JSON array at :path should contain only company :fixture')]
    public function jsonArrayContainsOnlyCompany(string $path, string $fixture): void
    {
        $rows = $this->jsonAt($path);
        $companyId = $this->fixtureId($fixture);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Expected a JSON array of company-scoped entries: ' . $path);
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['company_id']) || $row['company_id'] !== $companyId) {
                throw new RuntimeException('Company-scoped response includes a foreign or unscoped item.');
            }
        }
    }

    #[Then('the JSON array at :path should contain company :fixture')]
    public function jsonArrayContainsCompany(string $path, string $fixture): void
    {
        $rows = $this->jsonAt($path);
        $companyId = $this->fixtureId($fixture);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Expected a JSON array of company-scoped entries: ' . $path);
        }
        foreach ($rows as $row) {
            if (is_array($row) && ($row['company_id'] ?? null) === $companyId) {
                return;
            }
        }
        throw new RuntimeException('Expected a profile belonging to the selected synthetic company.');
    }
}
