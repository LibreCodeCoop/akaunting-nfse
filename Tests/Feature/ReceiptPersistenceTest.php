<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Tests\Feature\FeatureTestCase;

final class ReceiptPersistenceTest extends FeatureTestCase
{
    public function testCurrentFlowCreatesThenUpdatesCurrentReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $persistence = new ReceiptPersistence();

        $first = $persistence->storeCurrent(
            $invoice->id,
            $this->receipt('1', str_repeat('1', 50)),
            '1',
        );
        $second = $persistence->storeCurrent(
            $invoice->id,
            $this->receipt('2', str_repeat('2', 50)),
            '2',
        );

        self::assertSame($first->id, $second->id);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(str_repeat('2', 50), $second->fresh()->chave_acesso);
    }

    public function testGroupedFlowCreatesIndependentReceiptPerGroup(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $persistence = new ReceiptPersistence();

        $a = $persistence->createGrouped(
            $invoice->id,
            $this->receipt('10', str_repeat('3', 50)),
            '10',
            '0107|010701|2.00',
        );
        $b = $persistence->createGrouped(
            $invoice->id,
            $this->receipt('11', str_repeat('4', 50)),
            '11',
            '0101|010101|5.00',
        );

        self::assertNotSame($a->id, $b->id);
        self::assertSame(2, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testReplacementCreatesNewReceiptAndPreservesOriginal(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $original = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '20',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
        ]);

        $replacement = (new ReceiptPersistence())->createReplacement(
            $invoice->id,
            $this->receipt('21', str_repeat('6', 50)),
            '21',
            $original,
        );

        self::assertNotSame($original->id, $replacement->id);
        self::assertSame($original->id, $replacement->replaces_receipt_id);
        self::assertSame('substituted', $original->fresh()->status);
        self::assertSame(str_repeat('5', 50), $original->fresh()->chave_acesso);
    }

    public function testReplacementRetryReturnsExistingReceiptWithoutDuplicatingHistory(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $original = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '40',
            'chave_acesso' => str_repeat('4', 50),
            'status' => 'emitted',
        ]);
        $persistence = new ReceiptPersistence();
        $remote = $this->receipt('41', str_repeat('8', 50));

        $first = $persistence->createReplacement($invoice->id, $remote, '41', $original);
        $retry = $persistence->createReplacement($invoice->id, $remote, '41', $original->fresh());

        self::assertSame($first->id, $retry->id);
        self::assertSame(
            1,
            NfseReceipt::query()->where('replaces_receipt_id', $original->id)->count(),
        );
        self::assertSame('substituted', $original->fresh()->status);

        $enriched = $persistence->createReplacement(
            $invoice->id,
            new ReceiptData(
                nfseNumber: '41',
                chaveAcesso: str_repeat('8', 50),
                dataEmissao: '2026-10-05T10:00:00-03:00',
                rawXml: '<NFSe><infNFSe><DPS><infDPS><dCompet>2026-10-01</dCompet><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS></infNFSe></NFSe>',
            ),
            '41',
            $original->fresh(),
        );

        self::assertSame($first->id, $enriched->id);
        self::assertSame('2026-10-01', $enriched->competence_date?->format('Y-m-d'));
    }

    public function testReplacementRetryRejectsDifferentRemoteReplacement(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $original = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '50',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
        ]);
        $persistence = new ReceiptPersistence();

        $persistence->createReplacement(
            $invoice->id,
            $this->receipt('51', str_repeat('6', 50)),
            '51',
            $original,
        );

        $this->expectException(\LogicException::class);

        $persistence->createReplacement(
            $invoice->id,
            $this->receipt('52', str_repeat('7', 50)),
            '52',
            $original->fresh(),
        );
    }

    public function testAuthorizedXmlPersistsCompetenceAndFiscalSnapshot(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $xml = '<NFSe><infNFSe><valores><vLiq>90.00</vLiq></valores><DPS><infDPS><dCompet>2026-10-01</dCompet><valores><vServPrest><vServ>100.00</vServ></vServPrest><trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN><vISSQN>2.00</vISSQN></tribMun></trib></valores></infDPS></DPS></infNFSe></NFSe>';

        $receipt = (new ReceiptPersistence())->storeCurrent(
            $invoice->id,
            new ReceiptData(
                nfseNumber: '30',
                chaveAcesso: str_repeat('7', 50),
                dataEmissao: '2026-10-05T10:00:00-03:00',
                rawXml: $xml,
            ),
            '30',
        );

        $fresh = $receipt->fresh();

        self::assertSame('2026-10-01', $fresh->competence_date?->format('Y-m-d'));
        self::assertSame('100.00', $fresh->authorized_fiscal_snapshot['gross_service_value'] ?? null);
        self::assertSame(hash('sha256', $xml), $fresh->authorized_fiscal_snapshot['source_sha256'] ?? null);
    }

    private function receipt(string $number, string $accessKey): ReceiptData
    {
        return new ReceiptData(
            nfseNumber: $number,
            chaveAcesso: $accessKey,
            dataEmissao: '2026-10-04T20:00:00-03:00',
        );
    }
}
