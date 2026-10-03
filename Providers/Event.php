<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use Modules\Nfse\Listeners\OverrideInvoiceEmailRoute;
use Modules\Nfse\Listeners\PersistItemFiscalProfile;

class Event extends Provider
{
    protected $listen = [
        'Illuminate\\Routing\\Events\\RouteMatched' => [
            OverrideInvoiceEmailRoute::class,
        ],
        'App\\Events\\Common\\ItemCreated' => [
            PersistItemFiscalProfile::class,
        ],
        'App\\Events\\Common\\ItemUpdated' => [
            PersistItemFiscalProfile::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return true;
    }

    protected function discoverEventsWithin(): array
    {
        return [
            __DIR__ . '/../Listeners',
        ];
    }
}
