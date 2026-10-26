<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use Tests\Feature\FeatureTestCase;

final class FiscalConsoleCompanyContextTest extends FeatureTestCase
{
    public function testMunicipalDiagnosticsRestoresEmptyCompanyContextAfterFailure(): void
    {
        $this->loginAs();
        $previous = $this->company;
        Company::forgetCurrent();

        try {
            // A missing synthetic profile is sufficient to exercise the
            // command's error cleanup without any upstream HTTP request.
            $this->artisan('nfse:municipal-diagnose', [
                'company' => (int) $previous->id,
                'service' => '010701000',
                'competence' => '2026-10-05',
            ])->assertExitCode(1);

            self::assertNull(Company::getCurrent());
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testAdnSyncRestoresEmptyCompanyContextAfterFailure(): void
    {
        $this->loginAs();
        $previous = $this->company;
        Company::forgetCurrent();

        try {
            // No active NFS-e profile: the synchronization fails without
            // contacting ADN, and must not leave the CLI process in this tenant.
            $this->artisan('nfse:adn-sync', [
                'company' => (int) $previous->id,
                '--max-pages' => 1,
            ])->assertExitCode(1);

            self::assertNull(Company::getCurrent());
        } finally {
            $previous->makeCurrent();
        }
    }
}
