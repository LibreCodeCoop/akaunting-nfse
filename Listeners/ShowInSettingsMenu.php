<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Menu\SettingsCreated as Event;
use App\Traits\Modules;
use App\Traits\Permissions;

class ShowInSettingsMenu
{
    use Modules;
    use Permissions;

    public function handle(Event $event): void
    {
        if (!$this->moduleIsEnabled('nfse')) {
            return;
        }

        $title = trans('nfse::general.name');

        if ($this->canAccessMenuItem($title, 'read-settings-company')) {
            $event->menu->route(
                'nfse.settings.edit',
                $title,
                [],
                260,
                [
                    'icon' => 'receipt_long',
                    'search_keywords' => trans('nfse::general.description'),
                ]
            );
        }
    }
}
