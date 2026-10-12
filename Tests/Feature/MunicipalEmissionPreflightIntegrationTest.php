<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\EmissionAttemptJournal;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Application\MunicipalEmissionPreflight;
use Modules\Nfse\Application\MunicipalIssuanceBlocked;
use Modules\Nfse\Application\OfficialMunicipalIssuanceEvidence;
use Modules\Nfse\Application\ReceiptNumberResolver;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\MunicipalParameterSnapshot;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Tests\Feature\FeatureTestCase;

/**
 * Actual Akaunting persistence + fake SEFIN client; never a fiscal network call.
 * The assessed context is the FINAL DPS, not today's item-catalog date.
 */
final class MunicipalEmissionPreflightIntegrationTest extends FeatureTestCase
{
    public function testInvalidNationalOrMunicipalProfileStopsMutablePost(): void
    {
        foreach ([
            ['national' => '', 'suffix' => '123', 'reason' => 'invalid_national_fiscal_profile'],
            ['national' => '999999', 'suffix' => '123', 'reason' => 'invalid_national_fiscal_profile'],
            ['national' => '010701', 'suffix' => '12', 'reason' => 'invalid_municipal_complement'],
        ] as $case) {
            $invoice = $this->invoiceWithProfiles(['123']);
            $client = $this->fakeSefin();
            $dps = $this->dps($invoice, $case['national'], $case['suffix']);
            $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];

            try {
                $this->issueFromJournal($invoice, $client, $dps, (string) $group['key']);
                self::fail('Invalid fiscal data must not reach SEFIN.');
            } catch (MunicipalIssuanceBlocked $error) {
                self::assertStringContainsString($case['reason'], $error->getMessage());
            }

            self::assertSame(0, $client->emits);
            self::assertSame(
                EmissionAttemptJournal::TECHNICAL_FAILURE,
                NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status,
            );
            self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        }
    }

    public function testUnavailableMunicipalEvidenceWarnsButPermitsOnePost(): void
    {
        $invoice = $this->invoiceWithProfiles(['123']);
        $dps = $this->dps($invoice);
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];

        // No fact establishes that the declared issuer municipality is the
        // actual ISS incidence municipality. An empty source is not rejection.
        $preflight = (new MunicipalEmissionPreflight())->evaluate($invoice, $dps, (string) $group['key']);
        self::assertTrue($preflight['can_attempt']);
        self::assertSame('unverifiable', $preflight['municipal']['decision']);
        self::assertSame('municipal_context_incomplete', $preflight['reason']);
        self::assertSame('2026-09-01', $preflight['context']['competence']);
        self::assertSame((int) $invoice->company_id, $preflight['context']['company_id']);
        self::assertSame('010701123', $preflight['context']['service_code']);
        self::assertSame('sandbox', $preflight['context']['environment']);

        $client = $this->fakeSefin();
        $this->issueFromJournal($invoice, $client, $dps, (string) $group['key']);
        self::assertSame(1, $client->emits);
        self::assertSame('authorized', NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);

        // An authorized receipt remains immutable, even if today's municipal
        // parameters are missing or a subsequent request repeats this DPS.
        $this->issueFromJournal($invoice, $client, $dps, (string) $group['key']);
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testMatched404SnapshotIsConsultativeAndNeverReusedForWrongContext(): void
    {
        $invoice = $this->invoiceWithProfiles(['123']);
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        MunicipalParameterSnapshot::query()->create([
            'company_id' => (int) $invoice->company_id,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701123',
            'competence_date' => '2026-09-01',
            'payload' => ['aliquota' => ['aliquotas' => []]],
            'source_provenance' => [
                'contract_version' => 'municipal-api-test-v1',
                'endpoints' => [
                    'aliquota' => [
                        'source_url' => 'https://adn.nfse.gov.br/parametrizacao/3303302/01.07.01.123/2026-09-01/aliquota',
                        'http_status' => 404,
                        'outcome' => 'not_found',
                    ],
                ],
            ],
            'fetched_at' => now(),
        ]);

        $resolver = new MunicipalEmissionPreflight();
        $facts = ['provider_establishment_confirmed' => true];
        $matched = $resolver->evaluate($invoice, $this->dps($invoice), (string) $group['key'], $facts);

        self::assertTrue($matched['can_attempt']);
        self::assertSame('unverifiable', $matched['municipal']['decision']);
        self::assertSame('municipal_404_inconclusive', $matched['reason']);
        self::assertSame(404, $matched['municipal']['source']['http_status']);
        self::assertSame('municipal-api-test-v1', $matched['municipal']['source']['contract_version']);
        self::assertSame('010701123', $matched['parameters']['source']['service_code']);

        foreach ([
            $this->dps($invoice, '010701', '123', '2026-10-01'),
            $this->dps($invoice, '010701', '222', '2026-09-01'),
            $this->dps($invoice, '010701', '123', '2026-09-01', 1),
        ] as $otherDps) {
            $other = $resolver->evaluate($invoice, $otherDps, (string) $group['key'], $facts);
            self::assertTrue($other['can_attempt']);
            self::assertSame('unverifiable', $other['municipal']['decision']);
            self::assertNull($other['parameters']);
            self::assertSame('municipal_source_unavailable', $other['reason']);
        }
    }

    public function testOnlyTypedOfficialRejectionOfExactContextBlocksPreflight(): void
    {
        $invoice = $this->invoiceWithProfiles(['123']);
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $dps = $this->dps($invoice);
        $facts = ['provider_establishment_confirmed' => true];
        $preflight = new MunicipalEmissionPreflight();
        $context = $preflight->evaluate($invoice, $dps, (string) $group['key'], $facts)['context'];
        $rejection = OfficialMunicipalIssuanceEvidence::fromRejection(
            $this->e0312(),
            $context,
            'sandbox',
            new \DateTimeImmutable('2026-10-12T10:00:00-03:00'),
        );
        self::assertInstanceOf(OfficialMunicipalIssuanceEvidence::class, $rejection);

        $blocked = $preflight->evaluate($invoice, $dps, (string) $group['key'], $facts, $rejection);
        self::assertFalse($blocked['can_attempt']);
        self::assertSame('rejected', $blocked['municipal']['decision']);
        self::assertSame('exact', $blocked['municipal']['binding']);

        $anotherCompetence = $preflight->evaluate(
            $invoice,
            $this->dps($invoice, '010701', '123', '2026-10-01'),
            (string) $group['key'],
            $facts,
            $rejection,
        );
        self::assertTrue($anotherCompetence['can_attempt']);
        self::assertSame('unverifiable', $anotherCompetence['municipal']['decision']);
    }

    public function testSameDpsE0312CannotPostTwiceButDifferentCompetenceCanBeTried(): void
    {
        $invoice = $this->invoiceWithProfiles(['123']);
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $client = $this->fakeSefin();
        $client->emitError = $this->e0312();
        $dps = $this->dps($invoice);

        try {
            $this->issueFromJournal($invoice, $client, $dps, (string) $group['key']);
            self::fail('Expected official E0312.');
        } catch (IssuanceException) {
        }

        $client->emitError = null;
        try {
            $this->issueFromJournal($invoice, $client, $dps, (string) $group['key']);
            self::fail('A repeated rejected DPS must not produce another POST.');
        } catch (MunicipalIssuanceBlocked $error) {
            self::assertStringContainsString('E0312', $error->getMessage());
        }

        self::assertSame(1, $client->emits);
        $different = $this->dps($invoice, '010701', '123', '2026-10-01');
        $this->issueFromJournal($invoice, $client, $different, (string) $group['key']);
        self::assertSame(2, $client->emits);
        self::assertSame(
            ['rejected', 'authorized'],
            NfseEmissionAttempt::query()->where('invoice_id', $invoice->id)
                ->orderBy('attempt_number')->pluck('status')->all(),
        );
    }

    public function testTwoFiscalGroupsWithDifferentMunicipalComplementsIssueIndependently(): void
    {
        $invoice = $this->invoiceWithProfiles(['123', '222']);
        $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        self::assertCount(2, $groups);
        $client = $this->fakeSefin();
        $issuer = new IssueInvoiceFiscalGroup();
        $baseDps = $this->dps($invoice);

        foreach ($groups as $group) {
            $result = $issuer->issue($client, (int) $invoice->id, $baseDps, $group);
            self::assertFalse($result['reused']);
            self::assertSame('emitted', $result['receipt']->status);
        }

        self::assertSame(2, $client->emits);
        self::assertSame(
            ['010701123', '010701222'],
            array_map(
                static fn (DpsData $dps): string => $dps->codigoTributacaoNacional
                    . $dps->codigoTributacaoMunicipal,
                $client->submitted,
            ),
        );
        self::assertSame(2, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());

        foreach ($groups as $group) {
            self::assertTrue($issuer->issue($client, (int) $invoice->id, $baseDps, $group)['reused']);
        }
        self::assertSame(2, $client->emits);
    }

    /** @param list<string> $suffixes */
    private function invoiceWithProfiles(array $suffixes): Document
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) company_id(),
            'issued_at' => '2026-09-01 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        foreach ($suffixes as $suffix) {
            $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
            $invoice->items()->create([
                'company_id' => (int) $invoice->company_id,
                'type' => 'item',
                'item_id' => $item->id,
                'name' => 'Synthetic consulting ' . $suffix,
                'quantity' => 1,
                'price' => '100.00',
                'total' => '100.00',
            ]);
            ItemFiscalProfile::query()->create([
                'company_id' => (int) $invoice->company_id,
                'item_id' => $item->id,
                'item_lista_servico' => '0107',
                'codigo_tributacao_nacional' => '010701',
                'codigo_tributacao_municipal' => $suffix,
            ]);
        }

        $invoice->load('items');

        return $invoice;
    }

    private function dps(
        Document $invoice,
        string $national = '010701',
        string $suffix = '123',
        string $competence = '2026-09-01',
        int $environment = 2,
    ): DpsData {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            codigoTributacaoNacional: $national,
            codigoTributacaoMunicipal: $suffix,
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Synthetic municipal preflight',
            tipoAmbiente: $environment,
            serie: '00001',
            numeroDps: (string) $invoice->id,
            dataCompetencia: $competence,
        );
    }

    /** @return array{receipt:NfseReceipt,remote_receipt:?ReceiptData,reused:bool} */
    private function issueFromJournal(Document $invoice, NfseClientInterface $client, DpsData $dps, string $key): array
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

    private function e0312(): IssuanceException
    {
        return new IssuanceException(
            'Synthetic SEFIN rejection',
            NfseErrorCode::IssuanceRejected,
            422,
            ['erros' => [['codigo' => 'E0312', 'descricao' => 'Servico nao administrado nesta competencia.']]],
        );
    }

    private function fakeSefin(): NfseClientInterface
    {
        return new class () implements NfseClientInterface {
            public int $emits = 0;
            /** @var list<DpsData> */
            public array $submitted = [];
            public ?\Throwable $emitError = null;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;
                $this->submitted[] = $dps;

                if ($this->emitError instanceof \Throwable) {
                    throw $this->emitError;
                }

                return new ReceiptData(
                    nfseNumber: (string) (500 + $this->emits),
                    chaveAcesso: str_repeat((string) (($this->emits % 9) + 1), 50),
                    dataEmissao: '2026-10-12T10:00:00-03:00',
                );
            }

            public function queryDps(string $idDps): string
            {
                return '';
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('No synthetic recovery was configured.');
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
    }
}
