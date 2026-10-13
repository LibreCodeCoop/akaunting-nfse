<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Tests\Feature\FeatureTestCase;

final class SettingsReadinessNavigationTest extends FeatureTestCase
{
    public function testAdministrativeScreenSeparatesCompanyFiscalStateFromVault(): void
    {
        $this->loginAs()->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertSee('nfse-companies-title', false)
            ->assertSee('nfse-vault-title', false)
            ->assertSee('vault-diagnostics', false)
            ->assertDontSee('nfse-readiness-summary', false);
    }
}
