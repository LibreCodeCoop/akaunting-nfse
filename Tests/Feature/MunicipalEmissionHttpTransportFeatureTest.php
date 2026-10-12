<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\EmissionAttemptJournal;
use Modules\Nfse\Application\MunicipalIssuanceBlocked;
use Modules\Nfse\Application\ReceiptNumberResolver;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Tests\Support\FakeHttpTransport;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\XmlSignerInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NfseClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\NoOpSecretStore;
use Tests\Feature\FeatureTestCase;

/**
 * Full emission transport contract: Akaunting persistence, scoped SDK, built
 * DPS XML and deterministic fake SEFIN HTTP. No real network or certificates.
 */
final class MunicipalEmissionHttpTransportFeatureTest extends FeatureTestCase
{
    public function testInvalidNationalProfileNeverCallsScopedSefinPost(): void
    {
        [$invoice, $key] = $this->invoiceWithGroup();
        $transport = new FakeHttpTransport($this->authorizedResponse());
        $client = $this->client($transport);

        try {
            $this->issue($invoice, $key, $client, $this->dps($invoice, '999999'));
            self::fail('Invalid national code reached the fiscal transport.');
        } catch (MunicipalIssuanceBlocked $error) {
            self::assertStringContainsString('invalid_national_fiscal_profile', $error->getMessage());
        }

        self::assertCount(0, $transport->requests);
        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(
            EmissionAttemptJournal::TECHNICAL_FAILURE,
            NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status,
        );
    }

    public function testValidDpsCreatesOneCompressedXmlPostAndNeverResendsAuthorizedInvoice(): void
    {
        [$invoice, $key] = $this->invoiceWithGroup();
        $transport = new FakeHttpTransport($this->authorizedResponse());
        $client = $this->client($transport);
        $dps = $this->dps($invoice);

        $first = $this->issue($invoice, $key, $client, $dps);
        $second = $this->issue($invoice, $key, $client, $dps);

        self::assertSame('10', $first['receipt']->nfse_number);
        self::assertTrue($second['reused']);
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]->method);
        self::assertStringEndsWith('/SefinNacional/nfse', $transport->requests[0]->url);

        $body = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['dpsXmlGZipB64'] ?? null);
        $xml = gzdecode((string) base64_decode((string) $body['dpsXmlGZipB64'], true));
        self::assertIsString($xml);
        self::assertStringContainsString('<cTribNac>010701</cTribNac>', $xml);
        self::assertStringContainsString('<dCompet>2026-09-01</dCompet>', $xml);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testSdkE0312BlocksIdenticalDpsAndNeverTreatsPreviousRejectionAsAuthorization(): void
    {
        [$invoice, $key] = $this->invoiceWithGroup();
        $transport = new FakeHttpTransport(new HttpResponseData(
            status: 422,
            body: json_encode([
                'erros' => [['codigo' => 'E0312', 'descricao' => 'Servico nao administrado na competencia da DPS.']],
            ], JSON_THROW_ON_ERROR),
        ));
        $client = $this->client($transport);
        $dps = $this->dps($invoice);

        try {
            $this->issue($invoice, $key, $client, $dps);
            self::fail('The simulated SEFIN rejection was not propagated.');
        } catch (IssuanceException) {
        }

        try {
            $this->issue($invoice, $key, $client, $dps);
            self::fail('The same DPS must not produce a second POST.');
        } catch (MunicipalIssuanceBlocked $error) {
            self::assertStringContainsString('E0312', $error->getMessage());
        }

        self::assertCount(1, $transport->requests);
        self::assertSame('rejected', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);
        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    /** @return array{Document,string} */
    private function invoiceWithGroup(): array
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) company_id(),
            'issued_at' => '2026-09-01 12:00:00',
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
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '123',
        ]);
        $invoice->load('items');
        $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        self::assertCount(1, $groups);

        return [$invoice, (string) $groups[0]['key']];
    }

    private function dps(Document $invoice, string $nationalCode = '010701'): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            codigoTributacaoNacional: $nationalCode,
            codigoTributacaoMunicipal: '123',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Synthetic fiscal service',
            tipoAmbiente: 2,
            serie: '00001',
            numeroDps: (string) $invoice->id,
            dataCompetencia: '2026-09-01',
        );
    }

    private function client(FakeHttpTransport $transport): NfseClient
    {
        $signer = new class () implements XmlSignerInterface {
            public function sign(string $xml, string $cnpj): string
            {
                return $xml;
            }
        };

        return new NfseClient(
            environment: new EnvironmentConfig(sandboxMode: true),
            cert: new CertConfig(
                cnpj: '11222333000181',
                pfxPath: '/dev/null',
                vaultPath: 'synthetic-test',
            ),
            secretStore: new NoOpSecretStore(),
            signer: $signer,
            transport: $transport,
        );
    }

    private function authorizedResponse(): HttpResponseData
    {
        $xml = file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);

        return new HttpResponseData(
            status: 201,
            body: json_encode([
                'tipoAmbiente' => 2,
                'versaoAplicativo' => 'SEFIN-FAKE',
                'dataHoraProcessamento' => '2026-10-12T10:00:00-03:00',
                'chaveAcesso' => str_repeat('8', 50),
                'nfseXmlGZipB64' => base64_encode(gzencode($xml)),
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{receipt:NfseReceipt,remote_receipt:?ReceiptData,reused:bool} */
    private function issue(Document $invoice, string $key, NfseClient $client, DpsData $dps): array
    {
        return (new EmissionAttemptJournal())->issue(
            client: $client,
            dps: $dps,
            invoiceId: (int) $invoice->id,
            origin: 'manual',
            groupKey: $key,
            persist: static fn (ReceiptData $remote): NfseReceipt => (new ReceiptPersistence())->createGrouped(
                invoiceId: (int) $invoice->id,
                receipt: $remote,
                resolvedNumber: (new ReceiptNumberResolver())->resolve($remote),
                groupKey: $key,
            ),
        );
    }
}
