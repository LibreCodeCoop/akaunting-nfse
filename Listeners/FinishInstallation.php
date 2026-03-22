<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Module\Installed as Event;
use App\Traits\Permissions;

class FinishInstallation
{
    use Permissions;

    public string $alias = 'nfse';

    public function handle(Event $event): void
    {
        if ($event->alias !== $this->alias) {
            return;
        }

        $this->updatePermissions();
    }

    protected function updatePermissions(): void
    {
        // c=create, r=read, u=update, d=delete
        $this->attachPermissionsToAdminRoles([
            $this->alias . '-settings' => 'r,u,d',
        ]);
    }
}
