<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Nfse\Application\FiscalGroupDpsIdentity;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Tests\Feature\FeatureTestCase;

final class FiscalGroupResumeTest extends FeatureTestCase
{
    public function testAlreadyIssuedGroupReturnsBeforeAnotherMutablePost(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $groupKey = 'service:0107|tax:010701|rate:2.00';

        $existing = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '7001',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
            'emission_group_key' => $groupKey,
        ]);

        $client = new class () implements NfseClientInterface {
            public int $emits = 0;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                throw new \LogicException('Existing fiscal group must not be posted again.');
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

        $result = (new IssueInvoiceFiscalGroup())->issue(
            $client,
            (int) $invoice->id,
            $this->baseDps(),
            $this->group($groupKey, '40.00'),
        );

        self::assertTrue($result['reused']);
        self::assertSame($existing->id, $result['receipt']->id);
        self::assertSame(0, $client->emits);
    }

    public function testNewGroupUsesStableIdentityAndPersistsOneReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $groupKey = 'service:0101|tax:010101|rate:3.00';

        $client = new class () implements NfseClientInterface {
            public int $emits = 0;
            public ?DpsData $lastDps = null;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                $this->lastDps = $dps;

                return new ReceiptData(
                    nfseNumber: '8001',
                    chaveAcesso: str_repeat('8', 50),
                    dataEmissao: '2026-10-05T10:00:00-03:00',
                );
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

        $result = (new IssueInvoiceFiscalGroup())->issue(
            $client,
            (int) $invoice->id,
            $this->baseDps(),
            $this->group($groupKey, '60.00'),
            additionalDescription: 'Centro de custo: CGIBR - Assessoria Geral',
        );

        self::assertFalse($result['reused']);
        self::assertSame(1, $client->emits);
        self::assertSame(FiscalGroupDpsIdentity::SERIES, $client->lastDps?->serie);
        self::assertSame('60.00', $client->lastDps?->valorServico);
        self::assertSame('0101', $client->lastDps?->itemListaServico);
        self::assertSame('010101', $client->lastDps?->codigoTributacaoNacional);
        self::assertSame('075', $client->lastDps?->codigoTributacaoMunicipal);
        self::assertSame("Servico fiscal do grupo\n\nCentro de custo: CGIBR - Assessoria Geral", $client->lastDps?->discriminacao);

        self::assertDatabaseHas('nfse_receipts', [
            'invoice_id' => $invoice->id,
            'emission_group_key' => $groupKey,
            'nfse_number' => '8001',
            'status' => 'emitted',
        ]);

        $attempt = NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame('manual_group', $attempt->origin);
        self::assertSame('authorized', $attempt->status);
        self::assertSame((int) $result['receipt']->id, (int) $attempt->receipt_id);

        $retry = (new IssueInvoiceFiscalGroup())->issue(
            $client,
            (int) $invoice->id,
            $this->baseDps(),
            $this->group($groupKey, '60.00'),
        );

        self::assertTrue($retry['reused']);
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(
            1,
            NfseReceipt::query()
                ->where('invoice_id', $invoice->id)
                ->where('emission_group_key', $groupKey)
                ->count(),
        );
    }

    public function testExistingGroupReceiptCannotBeReadAcrossCompanies(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $key = 'service:0107|tax:010701|rate:2.00';

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9901',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'emission_group_key' => $key,
        ]);
        $invoice->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();

        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Must not be posted.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Must not be queried.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                return false;
            }

            public function getDanfse(string $nfseXml): string
            {
                return '';
            }
        };

        $this->expectException(AuthorizationException::class);

        (new IssueInvoiceFiscalGroup())->issue(
            $client,
            (int) $invoice->id,
            $this->baseDps(),
            $this->group($key, '50.00'),
        );
    }

    private function baseDps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Documento contabil completo',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function group(string $key, string $amount): array
    {
        return [
            'key' => $key,
            'item_lista_servico' => str_contains($key, '0101') ? '0101' : '0107',
            'codigo_tributacao_nacional' => str_contains($key, '010101') ? '010101' : '010701',
            'aliquota' => str_contains($key, '3.00') ? '3.00' : '2.00',
            'codigo_tributacao_municipal' => '075',
            'amount' => $amount,
            'items' => [
                [
                    'document_item_id' => 1,
                    'item_id' => 1,
                    'name' => 'Servico fiscal do grupo',
                    'amount' => $amount,
                ],
            ],
        ];
    }
}
