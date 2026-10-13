<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Listeners\EmitNfseBeforeDocumentSend;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Tests\Feature\FeatureTestCase;

final class EmitNfseBeforeDocumentSendCompanyTest extends FeatureTestCase
{
    public function testNoFiscalProfileDoesNotUseLegacyEmitOnSendPolicy(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        setting(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();
        $issuer = $this->issuer();

        (new EmitNfseBeforeDocumentSend(new AutomaticInvoiceEmissionPreflight(), $issuer))
            ->handle((object) ['document' => $invoice]);

        self::assertSame(0, $issuer->calls);
    }

    public function testProfileManualPolicyOverridesConflictingLegacyAutomaticSetting(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $this->profile((int) $invoice->company_id, 'manual');
        setting(['nfse.emission_policy' => 'emit_on_send']);
        setting()->save();
        $issuer = $this->issuer();

        (new EmitNfseBeforeDocumentSend(new AutomaticInvoiceEmissionPreflight(), $issuer))
            ->handle((object) ['document' => $invoice]);

        self::assertSame(0, $issuer->calls);
    }

    public function testProfileAutomaticPolicyBlocksUnreadyDpsBeforeOpeningClient(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $this->profile((int) $invoice->company_id, 'emit_on_send');
        setting(['nfse.emission_policy' => 'manual']);
        setting()->save();
        $issuer = $this->issuer();

        try {
            (new EmitNfseBeforeDocumentSend(new AutomaticInvoiceEmissionPreflight(), $issuer))
                ->handle((object) ['document' => $invoice]);
            self::fail('Unready fiscal group must block native document send.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Automatic NFS-e emission blocked', $error->getMessage());
        }
        self::assertSame(0, $issuer->calls);
    }

    /** @return BulkEmissionUnitIssuerInterface&object */
    private function issuer(): BulkEmissionUnitIssuerInterface
    {
        return new class () implements BulkEmissionUnitIssuerInterface {
            public int $calls = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                ++$this->calls;

                throw new \LogicException('No SEFIN operation must run in this test.');
            }
        };
    }

    private function profile(int $companyId, string $policy): void
    {
        NfseCompanyProfile::query()->create([
            'company_id' => $companyId,
            'status' => 'active',
            'certificate_ref' => NfseCompanyCertificatePath::newReference(),
            'certificate_cnpj' => '11222333000181',
            'certificate_valid_from' => time() - 3600,
            'certificate_valid_to' => time() + 3600,
            'fiscal_parameters' => [
                'emission_policy' => $policy,
                'opcao_simples_nacional' => '1',
                'ibs_cbs_enabled' => false,
            ],
        ]);
    }
}
