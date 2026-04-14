<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Events\Module {
    if (!class_exists(Enabled::class, false)) {
        class Enabled
        {
            public function __construct(public string $alias, public int $company_id = 1)
            {
            }
        }
    }
}

namespace App\Traits {
    if (!trait_exists(Permissions::class, false)) {
        trait Permissions
        {
            /**
             * @param array<string, string> $permissions
             */
            public function attachPermissionsToAdminRoles(array $permissions): void
            {
            }
        }
    }
}

namespace Modules\Nfse\Tests\Unit\Listeners {
    use App\Events\Module\Enabled;
    use Modules\Nfse\Listeners\FinishEnabling;
    use Modules\Nfse\Tests\TestCase;

    final class FinishEnablingTest extends TestCase
    {
        public function testHandleAttachesSettingsPermissionsForNfseEnable(): void
        {
            $listener = new class () extends FinishEnabling {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public bool $templatesSynced = false;

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function syncEmailTemplates(): void
                {
                    $this->templatesSynced = true;
                }
            };

            $listener->handle(new Enabled('nfse'));

            self::assertSame(['nfse-settings' => 'r,u,d'], $listener->attachedPermissions);
            self::assertTrue($listener->templatesSynced);
        }

        public function testHandleSkipsWhenEnabledAliasIsNotNfse(): void
        {
            $listener = new class () extends FinishEnabling {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public bool $templatesSynced = false;

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function syncEmailTemplates(): void
                {
                    $this->templatesSynced = true;
                }
            };

            $listener->handle(new Enabled('another-module'));

            self::assertSame([], $listener->attachedPermissions);
            self::assertFalse($listener->templatesSynced);
        }
    }
}
