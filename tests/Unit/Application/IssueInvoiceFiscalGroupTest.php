<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FiscalGroupDpsIdentity;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Application\IssueInvoiceNfse;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Application\RuntimeDpsFactory;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\TestCase;

final class IssueInvoiceFiscalGroupTest extends TestCase
{
    public function testExistingGroupReturnsBeforeRemotePost(): void
    {
        $existing = new NfseReceipt();
        $existing->id = 9;

        $persistence = new class ($existing) extends ReceiptPersistence {
            public int $creates = 0;

            public function __construct(private readonly NfseReceipt $existing)
            {
            }

            public function findGrouped(int $invoiceId, string $groupKey): ?NfseReceipt
            {
                return $this->existing;
            }

            public function createGrouped(int $invoiceId, ReceiptData $receipt, string $resolvedNumber, string $groupKey): NfseReceipt
            {
                $this->creates++;
                throw new \LogicException('Must not persist a second receipt.');
            }
        };

        $client = new class () implements NfseClientInterface {
            public int $emits = 0;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                throw new \LogicException('Must not emit an existing group.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $service = new IssueInvoiceFiscalGroup(
            identity: new FiscalGroupDpsIdentity(),
            issuer: new IssueInvoiceNfse(),
            persistence: $persistence,
            dpsFactory: new RuntimeDpsFactory(),
        );

        $result = $service->issue($client, 10, $this->baseDps(), [
            'key' => 'group-a',
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'aliquota' => '2.00',
            'amount' => '50.00',
            'line_items' => ['[0107] Consultoria'],
        ]);

        self::assertTrue($result['reused']);
        self::assertSame($existing, $result['receipt']);
        self::assertSame(0, $client->emits);
        self::assertSame(0, $persistence->creates);
    }

    private function baseDps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Base',
        );
    }
}
