<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionRunRecorder;
use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Jobs\ProcessBulkEmissionUnit;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionUnitProcessorTest extends FeatureTestCase
{
    public function testSuccessfulUnitPersistsReceiptAndCompletesRun(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-a',
            ]],
        );
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9001',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'emission_group_key' => 'group-a',
        ]);

        $issuer = new class ($receipt) implements BulkEmissionUnitIssuerInterface {
            public function __construct(private readonly NfseReceipt $receipt)
            {
            }

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                return $this->receipt;
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process(
            (int) $recorded['units'][0]->id,
        );

        self::assertSame(BulkEmissionStatusPolicy::ISSUED, $unit->status);
        self::assertSame($receipt->id, $unit->receipt_id);
        self::assertSame(
            'completed',
            $recorded['run']->fresh()?->status,
        );
    }

    public function testAmbiguousNetworkFailureBecomesRetryableReadErrorWithoutAutomaticRetry(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-a',
            ]],
        );

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public int $calls = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->calls++;
                throw new NetworkException('Ambiguous response after POST.');
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process(
            (int) $recorded['units'][0]->id,
        );

        self::assertSame(1, $issuer->calls);
        self::assertSame(BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR, $unit->status);
        self::assertSame('network', $unit->error_type);
        self::assertSame('partial_retryable', $recorded['run']->fresh()?->status);
    }

    public function testQueuedJobProcessesExactlyItsPersistedUnitWithoutFrameworkRetryLoop(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-job',
            ]],
        );
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9002',
            'chave_acesso' => str_repeat('8', 50),
            'status' => 'emitted',
            'emission_group_key' => 'group-job',
        ]);

        $issuer = new class ($receipt) implements BulkEmissionUnitIssuerInterface {
            public int $calls = 0;

            public function __construct(private readonly NfseReceipt $receipt)
            {
            }

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->calls++;

                return $this->receipt;
            }
        };

        $processor = new BulkEmissionUnitProcessor($issuer);
        $job = new ProcessBulkEmissionUnit((int) $recorded['units'][0]->id);

        self::assertSame(1, $job->tries);

        $job->handle($processor);

        self::assertSame(1, $issuer->calls);
        self::assertSame(
            BulkEmissionStatusPolicy::ISSUED,
            $recorded['units'][0]->fresh()?->status,
        );
    }

    public function testTerminalUnitIsIdempotentAndDoesNotInvokeIssuerAgain(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-a',
            ]],
        );
        $recorded['units'][0]->update(['status' => BulkEmissionStatusPolicy::BLOCKED]);

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public int $calls = 0;

            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                $this->calls++;
                throw new \LogicException('Must not be called.');
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process(
            (int) $recorded['units'][0]->id,
        );

        self::assertSame(BulkEmissionStatusPolicy::BLOCKED, $unit->status);
        self::assertSame(0, $issuer->calls);
    }
}
