<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Menu\AdminCreated as Event;
use App\Traits\Modules;
use App\Traits\Permissions;

class AddToAdminMenu
{
    use Modules;
    use Permissions;

    public function handle(Event $event): void
    {
        if (!$this->moduleIsEnabled('nfse')) {
            return;
        }

        $title = $this->menuTitle();

        if (!$this->canAccessMenuItem($title, 'read-nfse-settings')) {
            return;
        }

        $event->menu->route(
            'nfse.dashboard.index',
            trans('nfse::general.dashboard.menu_title'),
            [],
            45,
            [
                'icon' => 'receipt_long',
                'search_keywords' => trans('nfse::general.description'),
            ]
        );
    }

    protected function menuTitle(): string
    {
        return trans('nfse::general.name');
    }
}
