<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Tests\Feature\FeatureTestCase;

final class SettingsReadinessNavigationTest extends FeatureTestCase
{
    public function testVaultReadinessBlockersLinkDirectlyToVaultTab(): void
    {
        setting()->set([
            'nfse.bao_addr' => '',
            'nfse.bao_mount' => '',
        ]);
        setting()->save();

        $response = $this->loginAs()
            ->get(route('nfse.settings.edit'))
            ->assertOk();

        $vaultUrl = route('nfse.settings.edit', ['tab' => 'vault']);

        $response
            ->assertSee(trans('nfse::general.readiness.checks.bao_addr'))
            ->assertSee(trans('nfse::general.readiness.checks.bao_mount'))
            ->assertSee($vaultUrl, false);
    }
}
