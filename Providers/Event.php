<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use Modules\Nfse\Listeners\AppendFiscalProfileToApiResource;
use Modules\Nfse\Listeners\EmitNfseBeforeDocumentSend;
use Modules\Nfse\Listeners\PersistContactFiscalProfile;
use Modules\Nfse\Listeners\PersistItemFiscalProfile;
use Modules\Nfse\Listeners\PersistNativeCategoryIssuance;

class Event extends Provider
{
    protected $listen = [
        'App\\Events\\Setting\\CategoryCreating' => [
            PersistNativeCategoryIssuance::class . '@creating',
        ],
        'App\\Events\\Setting\\CategoryUpdating' => [
            PersistNativeCategoryIssuance::class . '@updating',
        ],
        'App\\Events\\Setting\\CategoryCreated' => [
            PersistNativeCategoryIssuance::class . '@created',
        ],
        'App\\Events\\Api\\ResourceShowing' => [
            AppendFiscalProfileToApiResource::class . '@showing',
        ],
        'App\\Events\\Common\\ContactCreating' => [
            PersistContactFiscalProfile::class . '@creating',
        ],
        'App\\Events\\Common\\ContactUpdating' => [
            PersistContactFiscalProfile::class . '@updating',
        ],
        'App\\Events\\Common\\SearchStringApplying' => [
            \Modules\Nfse\Listeners\ApplyNativeInvoiceFiscalFilter::class,
        ],

        'App\\Events\\Common\\SearchStringApplied' => [
            \Modules\Nfse\Listeners\RestoreNativeInvoiceSearch::class,
        ],

        'App\\Events\\Document\\DocumentSending' => [
            EmitNfseBeforeDocumentSend::class,
        ],
        'App\\Events\\Common\\ItemCreating' => [
            PersistItemFiscalProfile::class . '@creating',
        ],
        'App\\Events\\Common\\ItemUpdating' => [
            PersistItemFiscalProfile::class . '@updating',
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
