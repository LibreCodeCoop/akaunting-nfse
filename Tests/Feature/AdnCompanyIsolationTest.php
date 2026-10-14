<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Http\Request;
use Modules\Nfse\Http\Controllers\AdnController;
use Modules\Nfse\Models\NfseReceipt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTestCase;

final class AdnCompanyIsolationTest extends FeatureTestCase
{
    public function testDistributionWithoutCompanyProfileFailsClosedEvenWithExplicitCnpj(): void
    {
        $this->loginAs();

        $this->get(route('nfse.adn.distribution', ['nsu' => 0]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'company_profile_unavailable');

        $this->get(route('nfse.adn.distribution', [
            'nsu' => 0,
            'cnpj' => '11222333000181',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'company_profile_unavailable');
    }

    public function testReviewAndReconciliationWithoutCompanyContextFailClosed(): void
    {
        $this->loginAs();
        $original = Company::getCurrent();
        self::assertNotNull($original);
        app()->forgetInstance(Company::class);

        try {
            $controller = new class () extends AdnController {
                public function queue(): array
                {
                    return $this->accountingReviewQueue();
                }

                public function matches(array $keys): array
                {
                    return $this->localReceiptMatches($keys);
                }
            };

            self::assertSame([], $controller->queue());
            self::assertSame([], $controller->matches([str_repeat('1', 50)]));

            foreach ([
                static fn () => $controller->index(),
                static fn () => $controller->ignore(1),
                static fn () => $controller->importDraft(1, new Request()),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Company-less fiscal review operation must be rejected.');
                } catch (HttpException $error) {
                    self::assertSame(404, $error->getStatusCode());
                }
            }
        } finally {
            app()->instance(Company::class, $original);
        }
    }

    public function testReconciliationNeverMatchesReceiptFromAnotherCompany(): void
    {
        $this->loginAs();

        $currentCompanyId = company_id();
        $currentInvoice = Document::factory()->invoice()->create([
            'company_id' => $currentCompanyId,
        ]);
        $otherInvoice = Document::factory()->invoice()->create([
            'company_id' => $currentCompanyId,
        ]);
        $otherInvoice->forceFill([
            'company_id' => $currentCompanyId + 1000,
        ])->saveQuietly();

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
