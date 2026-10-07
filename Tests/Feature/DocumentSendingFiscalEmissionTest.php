<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Document\DocumentSending;
use App\Jobs\Document\SendDocument;
use App\Models\Common\Item;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class DocumentSendingFiscalEmissionTest extends FeatureTestCase
{
    public function testManualPolicyLeavesNativeSendLifecycleUntouched(): void
    {
        setting()->set(['nfse.emission_policy' => 'manual']);
        setting()->save();

        $invoice = $this->invoiceWithProfile();
        $issuer = $this->fakeIssuer();

        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        event(new DocumentSending($invoice));

        self::assertSame([], $issuer->calls);
    }

    public function testEmitOnSendIssuesReadyInvoiceBeforeCustomerDelivery(): void
    {
        setting()->set(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();

        $invoice = $this->invoiceWithProfile();
        $issuer = $this->fakeIssuer();

        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        event(new DocumentSending($invoice));

        self::assertCount(1, $issuer->calls);
        self::assertSame((int) $invoice->id, $issuer->calls[0]['invoice_id']);
        self::assertSame(
            'service:0107|tax:010701|mun:|rate:5.00',
            $issuer->calls[0]['group_key'],
        );
    }

    public function testEmitOnSendBlocksCustomerDeliveryWhenPreflightNeedsReview(): void
    {
        setting()->set(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();

        $invoice = $this->invoiceWithProfile();
        $invoice->contact->forceFill(['country' => 'GB'])->saveQuietly();
        $invoice->unsetRelation('contact');

        $issuer = $this->fakeIssuer();
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('foreign_taker_requires_review');

        event(new DocumentSending($invoice));
    }

    public function testNativeSendJobDoesNotNotifyCustomerWhenFiscalIssuanceFails(): void
    {
        setting()->set(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();

        $invoice = $this->invoiceWithProfile();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();
        $invoice->unsetRelation('contact');
        $invoice->load(['contact', 'items']);

        // Isolate the assertion window from notifications emitted by Akaunting
        // factories while building the fixture.
        Notification::fake();

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                throw new \RuntimeException('synthetic fiscal failure');
            }
        };

        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        try {
            (new SendDocument($invoice))->handle();
            self::fail('Native send must abort when fiscal issuance fails.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic fiscal failure', $e->getMessage());
        }

        Notification::assertNothingSent();
    }

    public function testRepeatSendWithExistingReceiptDoesNotIssueAgain(): void
    {
        setting()->set(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();

        $invoice = $this->invoiceWithProfile();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9001',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'emission_group_key' => 'service:0107|tax:010701|mun:|rate:5.00',
        ]);

        $issuer = $this->fakeIssuer();
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        event(new DocumentSending($invoice));

        self::assertSame([], $issuer->calls);
    }

    private function invoiceWithProfile(): Document
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['country' => 'BR'])->saveQuietly();
        $invoice->unsetRelation('contact');
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $item = Item::factory()->create(['company_id' => $invoice->company_id]);

        $invoice->items()->create([
            'company_id' => $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Consultoria',
            'quantity' => 1,
            'price' => '100',
            'total' => '100',
        ]);

        ItemFiscalProfile::query()->create([
            'company_id' => $invoice->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $invoice->load(['items', 'contact']);

        return $invoice;
    }

    private function fakeIssuer(): BulkEmissionUnitIssuerInterface
    {
        return new class () implements BulkEmissionUnitIssuerInterface {
            /** @var list<array{invoice_id:int,group_key:string}> */
            public array $calls = [];

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->calls[] = [
                    'invoice_id' => $invoiceId,
                    'group_key' => $emissionGroupKey,
                ];

                return new NfseReceipt([
                    'invoice_id' => $invoiceId,
                    'nfse_number' => 'FAKE-1',
                    'status' => 'emitted',
                    'emission_group_key' => $emissionGroupKey,
                ]);
            }
        };
    }
}
