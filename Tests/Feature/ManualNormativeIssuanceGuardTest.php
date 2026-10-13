<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Independent CGNFS-e 2026 obligation boundary through the actual HTTP
 * controller, without certificates or fiscal traffic.
 *
 * The fake gateway would have no reason to reject omitted IBS/CBS in 2026;
 * the controller must block before opening it. The #429 unified last-POST
 * contract owns future consolidation of these checks.
 */
final class ManualNormativeIssuanceGuardTest extends FeatureTestCase
{
    /**
     * @return iterable<string,array{string,string,int,string,string,string}>
     */
    public static function mandatoryIbsCbsDates(): iterable
    {
        yield 'ordinary manual on 01 October' => ['manual', '2026-10-01', 1, '0107', '010701', '2026-10-01'];
        yield 'ordinary reemission on 01 October' => ['reemit', '2026-10-01', 1, '0107', '010701', '2026-10-01'];
        yield 'deferred manual on 01 December' => ['manual', '2026-12-01', 1, '0103', '010301', '2026-12-01'];
        yield 'deferred reemission on 01 December' => ['reemit', '2026-12-01', 1, '0103', '010301', '2026-12-01'];
        yield 'Simples manual in 2027' => ['manual', '2027-01-01', 3, '0107', '010701', '2027-01-01'];
        yield 'Simples reemission in 2027' => ['reemit', '2027-01-01', 3, '0107', '010701', '2027-01-01'];
    }

    #[DataProvider('mandatoryIbsCbsDates')]
    public function testIncompleteRequiredIbsCbsNeverOpensSefinOrChangesFiscalHistory(
        string $operation,
        string $date,
        int $simples,
        string $service,
        string $national,
        string $effectiveDate,
    ): void {
        $invoice = $this->invoice($date, $service, $national);
        setting([
            'nfse.opcao_simples_nacional' => $simples,
            'nfse.ibs_cbs_enabled' => false,
            'nfse.enforce_item_federal_taxes' => false,
            'nfse.federal_piscofins_situacao_tributaria' => '',
        ]);
        setting()->save();

        $original = null;
        $archivedXml = '<NFSe><infNFSe><DPS><infDPS><dCompet>2026-09-01</dCompet></infDPS></DPS></infNFSe></NFSe>';
        if ($operation === 'reemit') {
            $original = NfseReceipt::query()->create([
                'invoice_id' => $invoice->id,
                'nfse_number' => '9001',
                'chave_acesso' => str_repeat('9', 50),
                'status' => 'cancelled',
            ]);
            $original->payload()->create(['authorized_xml' => $archivedXml]);
        }

        $controller = new class () extends InvoiceController {
            public int $clientOpens = 0;

            protected function emissionReadiness(): array
            {
                return ['isReady' => true, 'checklist' => []];
            }

            protected function makeClient(bool $sandboxMode, ?int $companyId = null): NfseClientInterface
            {
                $this->clientOpens++;

                throw new \LogicException('An incomplete required fiscal payload must never reach SEFIN.');
            }
        };

        $request = new Request();
        $response = $operation === 'manual'
            ? $controller->emit($invoice, $request)
            : $controller->reemit($invoice, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString($effectiveDate, (string) session()->get('error', ''));
        self::assertSame(0, $controller->clientOpens, 'No gateway construction, much less a POST.');
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());

        $receipts = NfseReceipt::query()->where('invoice_id', $invoice->id);
        self::assertSame($operation === 'manual' ? 0 : 1, $receipts->count());

        if ($original instanceof NfseReceipt) {
            self::assertSame('cancelled', (string) $original->fresh()->status);
            self::assertSame(str_repeat('9', 50), (string) $original->fresh()->chave_acesso);
            self::assertSame($archivedXml, $original->fresh()->payload()->value('authorized_xml'));
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unverifiableSimplesCases(): iterable
    {
        foreach (['emit', 'reemit'] as $operation) {
            yield $operation . ' missing' => [$operation, null];
            yield $operation . ' empty' => [$operation, ''];
            yield $operation . ' invalid' => [$operation, 4];
            yield $operation . ' leading zero' => [$operation, '02'];
            yield $operation . ' boolean' => [$operation, true];
        }
    }

    #[DataProvider('unverifiableSimplesCases')]
    public function testUnknownSimplesBlocksManualIssuanceWithoutChangingFiscalHistory(
        string $operation,
        mixed $simples,
    ): void {
        $invoice = $this->invoice('2026-09-30', '0107', '010701');
        setting([
            'nfse.opcao_simples_nacional' => $simples,
            'nfse.ibs_cbs_enabled' => false,
            'nfse.enforce_item_federal_taxes' => false,
        ]);
        setting()->save();

        $authorizedXml = '<NFSe><infNFSe><DPS><infDPS>synthetic</infDPS></DPS></infNFSe></NFSe>';
        $receipt = null;
        if ($operation === 'reemit') {
            $receipt = NfseReceipt::query()->create([
                'invoice_id' => $invoice->id,
                'nfse_number' => '9001',
                'chave_acesso' => str_repeat('9', 50),
                'status' => 'cancelled',
            ]);
            $receipt->payload()->create(['authorized_xml' => $authorizedXml]);
        }

        $controller = new class () extends InvoiceController {
            public int $clientOpens = 0;

            protected function makeClient(bool $sandboxMode, ?int $companyId = null): NfseClientInterface
            {
                $this->clientOpens++;

                throw new \LogicException('Unverified taxpayer status must never open a fiscal client.');
            }
        };

        $request = new Request();
        $response = $operation === 'emit'
            ? $controller->emit($invoice, $request)
            : $controller->reemit($invoice, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            trans('nfse::general.invoices.simples_option_unverifiable'),
            (string) session()->get('error', ''),
        );
        self::assertSame(0, $controller->clientOpens);
        self::assertSame(0, NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame($receipt === null ? 0 : 1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        if ($receipt instanceof NfseReceipt) {
            self::assertSame('cancelled', (string) $receipt->fresh()->status);
            self::assertSame($authorizedXml, $receipt->fresh()->payload()->value('authorized_xml'));
        }
    }

    private function invoice(string $date, string $service, string $national): Document
    {
        // Akaunting needs the active tenant for native redirect route defaults,
        // even though this test invokes the controller without an HTTP request.
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create([
            'issued_at' => $date . ' 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
        $invoice->items()->create([
            'company_id' => (int) $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Synthetic consulting service',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'item_id' => (int) $item->id,
            'item_lista_servico' => $service,
            'codigo_tributacao_nacional' => $national,
            'rtc_supply_category' => 'ordinary_lc116',
        ]);
        $invoice->load('items', 'contact');

        return $invoice;
    }
}
