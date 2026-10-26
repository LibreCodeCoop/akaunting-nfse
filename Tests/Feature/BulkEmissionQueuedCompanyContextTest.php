<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\BulkEmissionRunRecorder;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Jobs\ProcessBulkEmissionUnit;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionQueuedCompanyContextTest extends FeatureTestCase
{
    public function testQueuedUnitRunsUnderPersistedOwnerAndRestoresPreviousWorkerTenant(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $owner = $this->otherCompany();
        $owner->makeCurrent();
        setting()->set(['nfse.webdav_url' => 'https://owner.example.test']);
        setting()->save();

        $invoice = Document::factory()->invoice()->draft()->create(['company_id' => $owner->id]);
        $unitId = $this->recordUnit((int) $owner->id, (int) $invoice->id);
        $receipt = $this->receipt($invoice);
        $issuer = new class ($receipt) implements BulkEmissionUnitIssuerInterface {
            /** @var array{int,string}|null */
            public ?array $context = null;

            public function __construct(private readonly NfseReceipt $receipt)
            {
            }

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->context = [
                    (int) Company::getCurrent()?->id,
                    (string) setting('nfse.webdav_url'),
                ];

                return $this->receipt;
            }
        };

        $previous->makeCurrent();
        try {
            (new ProcessBulkEmissionUnit($unitId))->handle(new BulkEmissionUnitProcessor($issuer));

            self::assertSame([(int) $owner->id, 'https://owner.example.test'], $issuer->context);
            self::assertSame((int) $previous->id, (int) Company::getCurrent()?->id);
            $this->assertDatabaseHas('nfse_bulk_emission_units', [
                'id' => $unitId,
                'status' => 'issued',
            ]);
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testQueuedUnitRestoresEmptyWorkerContextAfterIssuerFailure(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $invoice = Document::factory()->invoice()->draft()->create();
        $unitId = $this->recordUnit((int) $invoice->company_id, (int) $invoice->id);
        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public int $observedCompanyId = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->observedCompanyId = (int) Company::getCurrent()?->id;
                throw new \RuntimeException('Synthetic pre-transmission failure');
            }
        };

        Company::forgetCurrent();
        try {
            try {
                (new ProcessBulkEmissionUnit($unitId))->handle(new BulkEmissionUnitProcessor($issuer));
                self::fail('Expected synthetic issuance failure.');
            } catch (\RuntimeException $error) {
                self::assertSame('Synthetic pre-transmission failure', $error->getMessage());
            }

            self::assertSame((int) $invoice->company_id, $issuer->observedCompanyId);
            self::assertNull(Company::getCurrent());
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testQueuedUnitRefusesRunInvoiceOwnershipMismatchBeforeProcessing(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $invoice = Document::factory()->invoice()->draft()->create();
        $unitId = $this->recordUnit((int) $invoice->company_id, (int) $invoice->id);
        $other = $this->otherCompany();
        DB::table('documents')->where('id', $invoice->id)->update(['company_id' => $other->id]);

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public int $calls = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                ++$this->calls;
                throw new \LogicException('Must never issue for a mismatched owner.');
            }
        };

        try {
            (new ProcessBulkEmissionUnit($unitId))->handle(new BulkEmissionUnitProcessor($issuer));
            self::fail('Expected mismatched owner to be rejected.');
        } catch (\DomainException $error) {
            self::assertSame('Bulk emission unit has no verified company owner.', $error->getMessage());
        }
        self::assertSame(0, $issuer->calls);
        self::assertSame((int) $previous->id, (int) Company::getCurrent()?->id);
        $this->assertDatabaseHas('nfse_bulk_emission_units', ['id' => $unitId, 'status' => 'queued']);
    }

    public function testBulkRunCannotPersistUnitsForAnotherCompany(): void
    {
        $this->loginAs();
        $owner = (int) $this->company->id;
        $other = $this->otherCompany();
        $invoice = Document::factory()->invoice()->draft()->create(['company_id' => $other->id]);

        try {
            $this->recordUnit($owner, (int) $invoice->id);
            self::fail('Expected cross-company bulk selection to be rejected.');
        } catch (\DomainException $error) {
            self::assertSame('Bulk emission invoices must belong to the run company.', $error->getMessage());
        }
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 0);
        $this->assertDatabaseCount('nfse_bulk_emission_units', 0);
    }

    private function otherCompany(): Company
    {
        $companyId = DB::table('companies')->insertGetId([
            'domain' => 'synthetic-bulk-tenant.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Company::query()->findOrFail($companyId);
    }

    private function recordUnit(int $companyId, int $invoiceId): int
    {
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: $companyId,
            requestedBy: null,
            units: [['invoice_id' => $invoiceId, 'emission_group_key' => 'synthetic-group']],
        );

        return (int) $recorded['units'][0]->id;
    }

    private function receipt(Document $invoice): NfseReceipt
    {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-QUEUED-OWNER',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
            'emission_group_key' => 'synthetic-group',
        ]);
    }
}
