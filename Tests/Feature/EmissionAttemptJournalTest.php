<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Nfse\Application\EmissionAttemptJournal;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Tests\Feature\FeatureTestCase;

final class EmissionAttemptJournalTest extends FeatureTestCase
{
    public function testAuthorizedAttemptLinksExistingReceiptAndXmlWithoutDoublePost(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $xml = (string) file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        $client->emitted = $this->receipt('101', '1', $xml);

        $first = $this->issue($invoice, $client);
        $second = $this->issue($invoice, $client);
        $attempt = NfseEmissionAttempt::query()->sole();

        self::assertFalse($first['reused']);
        self::assertTrue($second['reused']);
        self::assertSame(1, $client->emits);
        self::assertSame($first['receipt']->id, $second['receipt']->id);
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(EmissionAttemptJournal::AUTHORIZED, $attempt->status);
        self::assertSame((int) $first['receipt']->id, (int) $attempt->receipt_id);
        self::assertSame('automatic', $attempt->origin);
        self::assertSame('2026-09-01', $attempt->competence_date?->format('Y-m-d'));
        self::assertSame('010701123', $attempt->codigo_servico);
        self::assertSame(
            $xml,
            NfseReceiptPayload::query()->where('receipt_id', $attempt->receipt_id)->value('authorized_xml'),
        );
        self::assertFalse(array_key_exists('authorized_xml', $attempt->getAttributes()));
    }

    public function testE0312AndOtherOfficialRejectionsAreRecordedWithoutCreatingReceipts(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $client->emitError = $this->rejection('E0312', 'Municipio nao administra o servico nesta competencia');

        try {
            $this->issue($invoice, $client);
            self::fail('Expected rejection.');
        } catch (IssuanceException) {
        }

        $first = NfseEmissionAttempt::query()->sole();
        self::assertSame(EmissionAttemptJournal::REJECTED, $first->status);
        self::assertSame('E0312', $first->official_code);
        self::assertStringContainsString('nesta competencia', (string) $first->official_message);
        self::assertSame(422, $first->http_status);
        self::assertNull($first->receipt_id);
        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());

        // A later operator attempt does not inherit a universal E0312 veto.
        $client->emitError = $this->rejection('E0040', 'Declaracao invalida');
        try {
            $this->issue($invoice, $client);
            self::fail('Expected rejection.');
        } catch (IssuanceException) {
        }

        self::assertSame(2, $client->emits);
        self::assertSame(
            ['E0312', 'E0040'],
            NfseEmissionAttempt::query()->orderBy('attempt_number')->pluck('official_code')->all(),
        );
        self::assertSame(
            [1, 2],
            NfseEmissionAttempt::query()->orderBy('attempt_number')->pluck('attempt_number')->all(),
        );
    }

    public function testTimeoutBecomesAmbiguousAndLaterDpsRecoveryNeverRepeatsPost(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $client->emitError = new NetworkException('timeout after POST');

        try {
            $this->issue($invoice, $client);
            self::fail('Expected ambiguous response.');
        } catch (NetworkException) {
        }

        self::assertSame(EmissionAttemptJournal::AMBIGUOUS, NfseEmissionAttempt::query()->sole()->status);
        self::assertSame(0, NfseReceipt::query()->count());
        self::assertSame(1, $client->emits);

        // First repeated read still cannot recover: no accidental second POST.
        try {
            $this->issue($invoice, $client);
            self::fail('Expected unresolved read-only recovery.');
        } catch (NetworkException) {
        }
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseEmissionAttempt::query()->count());

        $client->recovered = $this->receipt('102', '2');
        $result = $this->issue($invoice, $client);
        self::assertSame('102', $result['receipt']->nfse_number);
        self::assertSame(1, $client->emits);
        self::assertGreaterThanOrEqual(2, $client->dpsQueries);
        self::assertSame(1, NfseReceipt::query()->count());
        self::assertSame(1, NfseEmissionAttempt::query()->count());

        $attempt = NfseEmissionAttempt::query()->sole();
        self::assertSame(EmissionAttemptJournal::AUTHORIZED, $attempt->status);
        self::assertNotNull($attempt->reconciled_at);

        $this->issue($invoice, $client);
        self::assertSame(1, $client->emits);
    }

    public function testHttp503IsNotRejectionAndReadOnlyRetryIsIdempotent(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $client->emitError = new IssuanceException(
            'Gateway unavailable',
            NfseErrorCode::IssuanceRejected,
            503,
            ['erros' => [['codigo' => 'E0312', 'descricao' => 'Unconfirmed body']]],
        );

        try {
            $this->issue($invoice, $client);
            self::fail('Expected gateway exception.');
        } catch (IssuanceException) {
        }

        $attempt = NfseEmissionAttempt::query()->sole();
        self::assertSame(EmissionAttemptJournal::AMBIGUOUS, $attempt->status);
        self::assertNull($attempt->official_code);
        self::assertSame(503, $attempt->http_status);
        self::assertSame(0, NfseReceipt::query()->count());

        try {
            $this->issue($invoice, $client);
            self::fail('Expected unresolved DPS.');
        } catch (NetworkException) {
        }

        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseEmissionAttempt::query()->count());
    }

    public function testLocalTechnicalFailureIsNotAnOfficialRejection(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $client->emitError = new PfxImportException('Local certificate failure');

        try {
            $this->issue($invoice, $client);
            self::fail('Expected local failure.');
        } catch (PfxImportException) {
        }

        $attempt = NfseEmissionAttempt::query()->sole();
        self::assertSame(EmissionAttemptJournal::TECHNICAL_FAILURE, $attempt->status);
        self::assertSame('local_preflight', $attempt->failure_class);
        self::assertNull($attempt->official_code);
        self::assertSame(0, NfseReceipt::query()->count());
    }

    public function testFiscalReceiptRollsBackTogetherWithAttemptLinkAndRecoverySucceeds(): void
    {
        $invoice = $this->invoice();
        $client = $this->client();
        $journal = new EmissionAttemptJournal();
        $dps = $this->dps();

        try {
            $journal->issue(
                client: $client,
                dps: $dps,
                invoiceId: (int) $invoice->id,
                origin: 'automatic',
                groupKey: 'group-a',
                persist: function (ReceiptData $remote) use ($invoice): NfseReceipt {
                    (new ReceiptPersistence())->createGrouped((int) $invoice->id, $remote, $remote->nfseNumber, 'group-a');
                    throw new \RuntimeException('Simulated database failure after receipt insert.');
                },
            );
            self::fail('Expected persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated database failure after receipt insert.', $exception->getMessage());
        }

        self::assertSame(0, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(EmissionAttemptJournal::AMBIGUOUS, NfseEmissionAttempt::query()->sole()->status);
        self::assertNull(NfseEmissionAttempt::query()->sole()->receipt_id);

        $client->recovered = $this->receipt('101', '1');
        $result = $this->issue($invoice, $client);
        self::assertSame('101', $result['receipt']->nfse_number);
        self::assertSame(1, $client->emits);
        self::assertSame(1, NfseEmissionAttempt::query()->count());
        self::assertSame(1, NfseReceipt::query()->count());
    }

    public function testCrossCompanyEmissionDeniedBeforeRemotePostAndHistoryScoped(): void
    {
        $invoice = $this->invoice();
        $this->issue($invoice, $this->client());

        $other = Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $other->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();
        $client = $this->client();

        try {
            $this->issue($other, $client);
            self::fail('Cross-company issuance must be denied.');
        } catch (AuthorizationException) {
        }

        self::assertSame(0, $client->emits);
        self::assertCount(0, (new EmissionAttemptJournal())->forInvoice((int) company_id(), (int) $other->id));
        self::assertCount(1, (new EmissionAttemptJournal())->forInvoice((int) company_id(), (int) $invoice->id));

        $this->expectException(AuthorizationException::class);
        (new EmissionAttemptJournal())->forInvoice((int) company_id() + 1000, (int) $other->id);
    }

    private function invoice(): Document
    {
        $this->loginAs();

        return Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
    }

    /** @return array{receipt:NfseReceipt,remote_receipt:?ReceiptData,reused:bool} */
    private function issue(Document $invoice, NfseClientInterface $client): array
    {
        return (new EmissionAttemptJournal())->issue(
            client: $client,
            dps: $this->dps(),
            invoiceId: (int) $invoice->id,
            origin: 'automatic',
            groupKey: 'group-a',
            persist: static fn (ReceiptData $remote): NfseReceipt => (new ReceiptPersistence())->createGrouped(
                invoiceId: (int) $invoice->id,
                receipt: $remote,
                resolvedNumber: $remote->nfseNumber,
                groupKey: 'group-a',
            ),
        );
    }

    private function dps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            codigoTributacaoNacional: '010701',
            codigoTributacaoMunicipal: '123',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Fiscal attempt regression',
            tipoAmbiente: 2,
            serie: '00002',
            numeroDps: '42',
            dataCompetencia: '2026-09-01',
        );
    }

    private function receipt(string $number, string $digit, ?string $xml = null): ReceiptData
    {
        return new ReceiptData(
            nfseNumber: $number,
            chaveAcesso: str_repeat($digit, 50),
            dataEmissao: '2026-10-08T12:00:00-03:00',
            rawXml: $xml,
        );
    }

    private function rejection(string $code, string $message): IssuanceException
    {
        return new IssuanceException('SEFIN rejected', NfseErrorCode::IssuanceRejected, 422, [
            'erros' => [['codigo' => $code, 'descricao' => $message]],
        ]);
    }

    private function client(): FakeEmissionClient
    {
        return new FakeEmissionClient();
    }
}

final class FakeEmissionClient implements NfseClientInterface
{
            public int $emits = 0;
            public int $dpsQueries = 0;
            public ?\Throwable $emitError = null;
            public ?ReceiptData $emitted = null;
            public ?ReceiptData $recovered = null;

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emits++;

                if ($this->emitError instanceof \Throwable) {
                    throw $this->emitError;
                }

                return $this->emitted ?? new ReceiptData('101', str_repeat('1', 50), '2026-10-08T12:00:00-03:00');
            }

            public function queryDps(string $idDps): string
            {
                $this->dpsQueries++;

                return $this->recovered?->chaveAcesso ?? '';
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                if (!$this->recovered instanceof ReceiptData) {
                    throw new \LogicException('No recovered receipt.');
                }

                return $this->recovered;
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
}
