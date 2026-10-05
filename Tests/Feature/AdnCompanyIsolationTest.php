<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Modules\Nfse\Http\Controllers\AdnController;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class AdnCompanyIsolationTest extends FeatureTestCase
{
    public function testReconciliationNeverMatchesReceiptFromAnotherCompany(): void
    {
        $this->loginAs();

        $currentCompanyId = company_id();
        $otherCompany = Company::factory()->enabled()->create();

        $currentInvoice = Document::factory()->invoice()->create([
            'company_id' => $currentCompanyId,
        ]);
        $otherInvoice = Document::factory()->invoice()->create([
            'company_id' => $otherCompany->id,
        ]);

        $currentAccessKey = str_repeat('1', 50);
        $otherAccessKey = str_repeat('2', 50);

        NfseReceipt::query()->create([
            'invoice_id' => $currentInvoice->id,
            'nfse_number' => '1001',
            'chave_acesso' => $currentAccessKey,
            'status' => 'emitted',
        ]);
        NfseReceipt::query()->create([
            'invoice_id' => $otherInvoice->id,
            'nfse_number' => '2001',
            'chave_acesso' => $otherAccessKey,
            'status' => 'emitted',
        ]);

        $controller = new class () extends AdnController {
            /**
             * @param list<string> $keys
             * @return array<string, array{invoice_id:int, nfse_number:string, status:string}>
             */
            public function matches(array $keys): array
            {
                return $this->localReceiptMatches($keys);
            }
        };

        $matches = $controller->matches([$currentAccessKey, $otherAccessKey]);

        self::assertArrayHasKey($currentAccessKey, $matches);
        self::assertArrayNotHasKey(
            $otherAccessKey,
            $matches,
            'ADN reconciliation must not expose a fiscal receipt owned by another Akaunting company.',
        );
    }
}
