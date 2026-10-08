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
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
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
            BulkEmissionStatusPolicy::ISSUED,
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
        self::assertSame(
            BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR,
            $recorded['run']->fresh()?->status,
        );
    }

    public function testVaultFailureBecomesReadinessBlocker(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-vault',
            ]],
        );

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                throw new SecretStoreException('Vault unavailable.');
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process(
            (int) $recorded['units'][0]->id,
        );

        self::assertSame(BulkEmissionStatusPolicy::BLOCKED, $unit->status);
        self::assertSame('readiness', $unit->error_type);
        self::assertSame('Vault unavailable.', $unit->error_message);
    }

    public function testPfxFailureBecomesReadinessBlocker(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-pfx',
            ]],
        );

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                throw new PfxImportException('Certificate cannot be imported.');
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process(
            (int) $recorded['units'][0]->id,
        );

        self::assertSame(BulkEmissionStatusPolicy::BLOCKED, $unit->status);
        self::assertSame('readiness', $unit->error_type);
        self::assertSame('Certificate cannot be imported.', $unit->error_message);
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

    public function testOfficialE0312MarksBatchUnitRejectedWithoutFakeReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [['invoice_id' => (int) $invoice->id, 'emission_group_key' => 'group-e0312']],
        );

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                throw new IssuanceException(
                    'Generic upstream failure',
                    NfseErrorCode::IssuanceRejected,
                    422,
                    ['erros' => [['codigo' => 'E0312', 'descricao' => 'Servico nao administrado na competencia']]],
                );
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process((int) $recorded['units'][0]->id);
        self::assertSame(BulkEmissionStatusPolicy::REJECTED, $unit->status);
        self::assertSame('official_rejection', $unit->error_type);
        self::assertStringContainsString('E0312', (string) $unit->error_message);
        self::assertNull($unit->receipt_id);
    }

    public function testUnconfirmedBatchHttp503CannotBecomeOfficialRejection(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [['invoice_id' => (int) $invoice->id, 'emission_group_key' => 'group-503']],
        );

        $issuer = new class () implements BulkEmissionUnitIssuerInterface {
            public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
            {
                throw new IssuanceException(
                    'Gateway temporarily failed',
                    NfseErrorCode::IssuanceRejected,
                    503,
                    ['erros' => [['codigo' => 'E0312', 'descricao' => 'Not a confirmed rejection']]],
                );
            }
        };

        $unit = (new BulkEmissionUnitProcessor($issuer))->process((int) $recorded['units'][0]->id);
        self::assertSame(BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR, $unit->status);
        self::assertSame('gateway_unconfirmed', $unit->error_type);
        self::assertNull($unit->receipt_id);
        self::assertSame(1, (new ProcessBulkEmissionUnit((int) $unit->id))->tries);
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
