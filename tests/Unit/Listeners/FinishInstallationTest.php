<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Events\Module {
    if (!class_exists(Installed::class, false)) {
        class Installed
        {
            public function __construct(public string $alias, public int $company_id = 1, public string $locale = 'pt-BR')
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

namespace App\Traits {
    if (!trait_exists(Jobs::class, false)) {
        trait Jobs
        {
            public function dispatch(mixed $job): mixed
            {
                return null;
            }
        }
    }
}

namespace Modules\Nfse\Tests\Unit\Listeners {
    use App\Events\Module\Installed;
    use Modules\Nfse\Listeners\FinishInstallation;
    use Modules\Nfse\Tests\TestCase;

    final class FinishInstallationTest extends TestCase
    {
        public function testHandleAttachesSettingsPermissionsForNfseInstall(): void
        {
            $listener = new class () extends FinishInstallation {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function createNfseEmailTemplates(\App\Events\Module\Installed $event): void
                {
                    // not under test here
                }
            };

            $listener->handle(new Installed('nfse'));

            self::assertSame(['nfse-settings' => 'r,u,d'], $listener->attachedPermissions);
        }

        public function testHandleSkipsWhenInstalledAliasIsNotNfse(): void
        {
            $listener = new class () extends FinishInstallation {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function createNfseEmailTemplates(\App\Events\Module\Installed $event): void
                {
                    // not under test here
                }
            };

            $listener->handle(new Installed('another-module'));

            self::assertSame([], $listener->attachedPermissions);
        }

        public function testHandleCreatesEmailTemplateForNfseInstall(): void
        {
            $listener = new class () extends FinishInstallation {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public ?\App\Events\Module\Installed $emailTemplateEvent = null;

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function createNfseEmailTemplates(\App\Events\Module\Installed $event): void
                {
                    $this->emailTemplateEvent = $event;
                }
            };

            $event = new Installed('nfse', 5);
            $listener->handle($event);

            self::assertNotNull($listener->emailTemplateEvent);
            self::assertSame(5, $listener->emailTemplateEvent->company_id);
        }

        public function testHandleDoesNotCreateEmailTemplateForNonNfseInstall(): void
        {
            $listener = new class () extends FinishInstallation {
                /** @var array<string, string> */
                public array $attachedPermissions = [];

                public bool $emailTemplateCreated = false;

                public function attachPermissionsToAdminRoles(array $permissions): void
                {
                    $this->attachedPermissions = $permissions;
                }

                protected function createNfseEmailTemplates(\App\Events\Module\Installed $event): void
                {
                    $this->emailTemplateCreated = true;
                }
            };

            $listener->handle(new Installed('another-module'));

            self::assertFalse($listener->emailTemplateCreated);
        }

        public function testCreateNfseEmailTemplatesReferencesCorrectAlias(): void
        {
            $source = file_get_contents(__DIR__ . '/../../../Listeners/FinishInstallation.php');

            self::assertStringContainsString("'nfse_issued_customer'", $source);
        }
    }
}
