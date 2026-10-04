<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\AdnSynchronizationService;
use Modules\Nfse\Models\AdnSyncDocument;
use Modules\Nfse\Models\AdnSyncState;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDocumentData;
use Tests\Feature\FeatureTestCase;

final class AdnSynchronizationTest extends FeatureTestCase
{
    public function testBatchIsIdempotentAndAdvancesCheckpoint(): void
    {
        $service = new AdnSynchronizationService();
        $batch = $this->distribution([
            new AdnDocumentData(11, str_repeat('1', 50), 'NFSE', null, '<NFSe/>'),
        ], 11);

        self::assertSame(11, $service->apply(company_id(), 'sandbox', $batch));
        self::assertSame(11, $service->apply(company_id(), 'sandbox', $batch));

        self::assertSame(1, AdnSyncDocument::query()->where('company_id', company_id())->count());
        self::assertSame(11, $service->cursor(company_id(), 'sandbox'));
    }

    public function testCancellationEventUpdatesMatchingReceiptAndRemainsAuditable(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $accessKey = str_repeat('2', 50);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '42',
            'chave_acesso' => $accessKey,
            'status' => 'emitted',
        ]);

        (new AdnSynchronizationService())->apply(
            company_id(),
            'sandbox',
            $this->distribution([
                new AdnDocumentData(12, $accessKey, 'EVENTO', '101101', '<evento/>'),
            ], 12),
        );

        self::assertSame('cancelled', $receipt->fresh()->status);
        self::assertDatabaseHas('nfse_adn_documents', [
            'company_id' => company_id(),
            'nsu' => 12,
            'tipo_evento' => '101101',
            'recognized_event' => 1,
        ]);
    }

    public function testUnknownEventIsStoredWithoutGuessingReceiptLifecycle(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $accessKey = str_repeat('3', 50);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '43',
            'chave_acesso' => $accessKey,
            'status' => 'emitted',
        ]);

        (new AdnSynchronizationService())->apply(
            company_id(),
            'sandbox',
            $this->distribution([
                new AdnDocumentData(13, $accessKey, 'EVENTO', '999999', '<evento/>'),
            ], 13),
        );

        self::assertSame('emitted', $receipt->fresh()->status);
        self::assertDatabaseHas('nfse_adn_documents', [
            'company_id' => company_id(),
            'nsu' => 13,
            'tipo_evento' => '999999',
            'recognized_event' => 0,
        ]);
    }

    public function testStateIsIsolatedByCompanyAndEnvironment(): void
    {
        $service = new AdnSynchronizationService();

        $service->apply(10, 'sandbox', $this->distribution([], 20));
        $service->apply(20, 'production', $this->distribution([], 30));

        self::assertSame(20, $service->cursor(10, 'sandbox'));
        self::assertSame(30, $service->cursor(20, 'production'));
        self::assertSame(0, $service->cursor(10, 'production'));
    }

    public function testFailureMetadataDoesNotAdvanceCheckpoint(): void
    {
        $service = new AdnSynchronizationService();
        $service->apply(company_id(), 'sandbox', $this->distribution([], 40));
        $service->markFailure(company_id(), 'sandbox', new \RuntimeException('temporary ADN failure'));

        self::assertSame(40, $service->cursor(company_id(), 'sandbox'));
        self::assertSame(
            'temporary ADN failure',
            AdnSyncState::query()
                ->where('company_id', company_id())
                ->where('environment', 'sandbox')
                ->firstOrFail()
                ->last_error,
        );
    }

    /**
     * @param list<AdnDocumentData> $documents
     */
    private function distribution(array $documents, int $lastNsu): AdnDistributionData
    {
        return new AdnDistributionData(
            statusProcessamento: 'PROCESSADO',
            documents: $documents,
            alerts: [],
            errors: [],
            ambiente: '2',
            versaoAplicativo: '1.0',
            dataHoraProcessamento: '2026-10-04T20:00:00-03:00',
            ultimoNsu: $lastNsu,
        );
    }
}
