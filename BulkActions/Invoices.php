<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\BulkActions;

use App\BulkActions\Sales\Invoices as CoreInvoices;
use Illuminate\Http\RedirectResponse;
use Modules\Nfse\Http\Controllers\BulkEmissionController;

final class Invoices extends CoreInvoices
{
    public $path = [
        'group' => 'nfse',
        'type' => 'invoices',
    ];

    public function __construct()
    {
        $this->actions['nfse'] = [
            'icon' => 'receipt_long',
            'name' => 'nfse::general.bulk.emit_selected',
            'message' => '',
            'permission' => 'update-sales-invoices',
        ];
    }

    public function nfse($request): RedirectResponse
    {
        $request->merge([
            'invoice_ids' => array_values((array) $request->get('selected', [])),
        ]);

        return app()->call(
            [app(BulkEmissionController::class), 'enqueue'],
            ['request' => $request],
        );
    }
}
