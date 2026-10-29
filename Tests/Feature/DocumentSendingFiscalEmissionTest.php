<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Document\DocumentSending;
use App\Jobs\Document\SendDocument;
use App\Models\Common\Company;
use App\Models\Common\Item;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
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
        setting()->set([
            'nfse.emission_policy' => 'emit_on_send',
            'nfse.opcao_simples_nacional' => '1',
        ]);
        setting()->save();

        $invoice = $this->invoiceWithProfile('emit_on_send');
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

        $invoice = $this->invoiceWithProfile('emit_on_send');
        $invoice->contact->forceFill(['country' => 'GB'])->saveQuietly();
        $invoice->unsetRelation('contact');

        $issuer = $this->fakeIssuer();
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('foreign_taker_requires_review');

        event(new DocumentSending($invoice));
    }

    public function testBackgroundNativeSendUsesPersistedInvoiceOwnerWithoutHttpSession(): void
    {
        $invoice = $this->invoiceWithProfile('emit_on_send');
        $previous = Company::getCurrent();

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            /** @var list<int> */
            public array $observedCompanies = [];

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->observedCompanies[] = (int) Company::getCurrent()?->id;

                return new NfseReceipt([
                    'invoice_id' => $invoiceId,
                    'nfse_number' => 'FAKE-CONSOLE',
                    'status' => 'emitted',
                    'emission_group_key' => $emissionGroupKey,
                ]);
            }
        };
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        Company::forgetCurrent();
        try {
            self::assertNull(Company::getCurrent());
            self::assertNull(auth()->user());
            event(new DocumentSending($invoice));

            self::assertSame([(int) $invoice->company_id], $issuer->observedCompanies);
            self::assertNull(Company::getCurrent());
        } finally {
            if ($previous instanceof Company) {
                $previous->makeCurrent();
            }
        }
    }

    public function testBackgroundNativeSendRestoresMissingCompanyAfterIssuerFailure(): void
    {
        $invoice = $this->invoiceWithProfile('emit_on_send');
        $previous = Company::getCurrent();

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public int $observedCompany = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->observedCompany = (int) Company::getCurrent()?->id;

                throw new \RuntimeException('Synthetic fiscal failure before any POST.');
            }
        };
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        Company::forgetCurrent();
        try {
            event(new DocumentSending($invoice));
            self::fail('Synthetic issuance failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic fiscal failure before any POST.', $error->getMessage());
            self::assertSame((int) $invoice->company_id, $issuer->observedCompany);
            self::assertNull(Company::getCurrent());
        } finally {
            if ($previous instanceof Company) {
                $previous->makeCurrent();
            }
        }
    }

    public function testAuthenticatedNativeSendWithoutCompanyIsDenied(): void
    {
        $invoice = $this->invoiceWithProfile('emit_on_send');
        $this->loginAs();
        $previous = Company::getCurrent();
        Company::forgetCurrent();

        try {
            event(new DocumentSending($invoice));
            self::fail('Authenticated send without company context must not infer ownership.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertNull(Company::getCurrent());
        } finally {
            if ($previous instanceof Company) {
                $previous->makeCurrent();
            }
        }
    }

    public function testNativeSendJobDoesNotNotifyCustomerWhenFiscalIssuanceFails(): void
    {
        setting()->set([
            'nfse.emission_policy' => 'emit_on_send',
            'nfse.opcao_simples_nacional' => '1',
        ]);
        setting()->save();

        $invoice = $this->invoiceWithProfile('emit_on_send');
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

    public function testEmitOnSendDoesNotInferTaxpayerStatusWhenMissing(): void
    {
        setting()->set([
            'nfse.emission_policy' => 'emit_on_send',
            'nfse.opcao_simples_nacional' => null,
        ]);
        setting()->save();

        $invoice = $this->invoiceWithProfile('emit_on_send', null);
        $issuer = $this->fakeIssuer();
        $this->app->instance(BulkEmissionUnitIssuerInterface::class, $issuer);

        try {
            event(new DocumentSending($invoice));
            self::fail('Unknown Simples option must block before fiscal issuance.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('simples_option_unverifiable', $exception->getMessage());
        }

        self::assertSame([], $issuer->calls);
    }

    public function testRepeatSendWithExistingReceiptDoesNotIssueAgain(): void
    {
        setting()->set(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();

        $invoice = $this->invoiceWithProfile('emit_on_send');

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

    private function invoiceWithProfile(string $policy = 'manual', mixed $simples = '1'): Document
    {
        $invoice = Document::factory()->invoice()->create([
            'issued_at' => '2026-09-30 12:00:00',
        ]);
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

        // Native send policy belongs to the owning company's NFS-e profile.
        // Legacy settings set above are deliberately not consulted by the listener.
        NfseCompanyProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'status' => 'active',
            'certificate_ref' => NfseCompanyCertificatePath::newReference(),
            'certificate_cnpj' => '11222333000181',
            'certificate_valid_from' => time() - 3600,
            'certificate_valid_to' => time() + 3600,
            'fiscal_parameters' => [
                'emission_policy' => $policy,
                'opcao_simples_nacional' => $simples,
                'ibs_cbs_enabled' => false,
            ],
        ]);

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
