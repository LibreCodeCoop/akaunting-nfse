<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Module\Enabled;
use App\Events\Module\Installed;
use App\Models\Auth\Permission;
use App\Models\Setting\EmailTemplate;
use Modules\Nfse\Listeners\FinishEnabling;
use Modules\Nfse\Listeners\FinishInstallation;
use Tests\Feature\FeatureTestCase;

final class ModuleLifecycleCharacterizationTest extends FeatureTestCase
{
    public function testInstallationCreatesModulePermissionsAndEmailTemplate(): void
    {
        $this->loginAs();

        $listener = new FinishInstallation();
        $listener->handle(new Installed('nfse', company_id(), 'en-GB'));

        foreach (['read-nfse-settings', 'update-nfse-settings', 'delete-nfse-settings'] as $permission) {
            self::assertTrue(
                Permission::query()->where('name', $permission)->exists(),
                'Missing installed permission: ' . $permission,
            );
        }

        self::assertSame(
            1,
            EmailTemplate::query()
                ->where('company_id', company_id())
                ->where('alias', 'invoice_nfse_issued_customer')
                ->count(),
        );
    }

    public function testInstallationEventForAnotherModuleDoesNotMutateNfseState(): void
    {
        $this->loginAs();

        $beforePermissions = Permission::query()
            ->whereIn('name', ['read-nfse-settings', 'update-nfse-settings', 'delete-nfse-settings'])
            ->count();

        $beforeTemplates = EmailTemplate::query()
            ->where('company_id', company_id())
            ->where('alias', 'invoice_nfse_issued_customer')
            ->count();

        (new FinishInstallation())->handle(new Installed('other-module', company_id(), 'en-GB'));

        self::assertSame(
            $beforePermissions,
            Permission::query()
                ->whereIn('name', ['read-nfse-settings', 'update-nfse-settings', 'delete-nfse-settings'])
                ->count(),
        );
        self::assertSame(
            $beforeTemplates,
            EmailTemplate::query()
                ->where('company_id', company_id())
                ->where('alias', 'invoice_nfse_issued_customer')
                ->count(),
        );
    }

    public function testInstallAndEnableLifecycleKeepsOneCanonicalEmailTemplate(): void
    {
        $this->loginAs();

        $listener = new FinishInstallation();
        $listener->handle(new Installed('nfse', company_id(), 'en-GB'));
        $listener->handle(new Installed('nfse', company_id(), 'en-GB'));

        $permissionCount = Permission::query()
            ->whereIn('name', ['read-nfse-settings', 'update-nfse-settings', 'delete-nfse-settings'])
            ->count();

        self::assertSame(
            1,
            EmailTemplate::query()
                ->where('company_id', company_id())
                ->where('alias', 'invoice_nfse_issued_customer')
                ->count(),
            'Repeated installation must not create duplicate canonical templates.',
        );

        (new FinishEnabling())->handle(new Enabled('nfse', company_id()));
        (new FinishEnabling())->handle(new Enabled('nfse', company_id()));

        self::assertSame(
            $permissionCount,
            Permission::query()
                ->whereIn('name', ['read-nfse-settings', 'update-nfse-settings', 'delete-nfse-settings'])
                ->count(),
        );

        self::assertSame(
            1,
            EmailTemplate::query()
                ->where('company_id', company_id())
                ->where('alias', 'invoice_nfse_issued_customer')
                ->count(),
            'Repeated enabling must preserve a single canonical template.',
        );
    }
}
