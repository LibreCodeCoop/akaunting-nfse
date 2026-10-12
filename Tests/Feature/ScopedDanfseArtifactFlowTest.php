<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\IssuedNfseArtifactStore;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Jobs\StoreIssuedNfseArtifacts;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Modules\Nfse\Support\WebDavClient;
use Tests\Feature\FeatureTestCase;

final class ScopedDanfseArtifactFlowTest extends FeatureTestCase
{
    public function testQueuedStoreProducesIsolatedScopedPdfForTwoCompaniesAndReusesStoredArtifacts(): void
    {
        $this->loginAs();
        $originalCompany = $this->company;
        $otherCompanyId = DB::table('companies')->insertGetId([
            'domain' => 'danfse-second.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCompany = Company::query()->findOrFail($otherCompanyId);
        $fixture = file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($fixture);

        $writes = [];
        $store = self::fakeWebDavStore($writes);
        $invoices = [];
        $receipts = [];

        try {
            foreach ([
                [$originalCompany, '11111111000191', 'EMPRESA SINTETICA ALFA'],
                [$otherCompany, '22222222000192', 'COOPERATIVA SINTETICA BETA'],
            ] as $index => [$company, $cnpj, $name]) {
                $company->makeCurrent();
                setting()->set(['nfse.cnpj_prestador' => $cnpj]);
                setting()->save();
                $invoice = Document::factory()->invoice()->draft()->create(['company_id' => $company->id]);
                $receipt = NfseReceipt::query()->create([
                    'invoice_id' => $invoice->id,
                    'nfse_number' => (string) (200 + $index),
                    'chave_acesso' => str_repeat((string) ($index + 1), 50),
                    'data_emissao' => '2026-10-07T10:00:00-03:00',
                    'status' => 'emitted',
                ]);
                $xml = str_replace('EMPRESA EXEMPLO DESENVOLVIMENTO LTDA', $name, $fixture);
                NfseReceiptPayload::query()->create([
                    'receipt_id' => $receipt->id,
                    'authorized_xml' => $xml,
                    'artifacts_status' => 'pending',
                    'email_status' => 'not_requested',
                ]);

                $job = new StoreIssuedNfseArtifacts((int) $invoice->id, (int) $receipt->id);
                $job->handle($store, new PostEmissionState());
                $job->handle($store, new PostEmissionState());

                $fresh = $receipt->fresh();
                self::assertNotEmpty($fresh->xml_webdav_path);
                self::assertNotEmpty($fresh->danfse_webdav_path);
                self::assertSame('completed', $receipt->payload()->value('artifacts_status'));
                self::assertSame($xml, $receipt->payload()->value('authorized_xml'));
                $invoices[] = $invoice;
                $receipts[] = $receipt;
            }

            $xmlWrites = array_values(array_filter($writes, static fn (array $write): bool => str_ends_with($write[0], '.xml')));
            $pdfWrites = array_values(array_filter($writes, static fn (array $write): bool => str_ends_with($write[0], '.pdf')));
            self::assertCount(2, $xmlWrites, 'Each company stores its authorized XML once.');
            self::assertCount(2, $pdfWrites, 'Each company stores its DANFSe once despite a second queue delivery.');
            self::assertNotSame($pdfWrites[0][0], $pdfWrites[1][0], 'WebDAV paths must remain tenant-specific.');
            self::assertNotSame($xmlWrites[0][1], $xmlWrites[1][1]);
            self::assertStringEndsWith((string) $receipts[0]->fresh()->danfse_webdav_path, $pdfWrites[0][0]);
            self::assertStringEndsWith((string) $receipts[1]->fresh()->danfse_webdav_path, $pdfWrites[1][0]);

            self::assertScopedPdf($pdfWrites[0][1], 'EMPRESA SINTETICA ALFA');
            self::assertScopedPdf($pdfWrites[1][1], 'COOPERATIVA SINTETICA BETA');

            $originalCompany->makeCurrent();
            $this->expectException(ModelNotFoundException::class);
            $store->store((int) $invoices[1]->id, (int) $receipts[1]->id);
        } finally {
            $originalCompany->makeCurrent();
        }
    }

    public function testMissingTypefaceFailsQueueWithoutPersistingPdfOrRetransmittingDps(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '300',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe>synthetic authorization</NFSe>',
            'artifacts_status' => 'pending',
            'email_status' => 'not_requested',
        ]);
        $writes = [];
        $store = new class ($writes) extends IssuedNfseArtifactStore {
            public function __construct(private array &$writes)
            {
            }

            protected function webDavEnabled(): bool
            {
                return true;
            }

            protected function storeXmlEnabled(): bool
            {
                return false;
            }

            protected function storePdfEnabled(): bool
            {
                return true;
            }

            protected function makeWebDavClient(): WebDavClient
            {
                return new WebDavClient(baseUrl: 'https://dav.example.test/root', request: function (string $method, string $url, array $headers, string $body): array {
                    if ($method === 'PUT') {
                        $this->writes[] = [$url, $body];
                    }

                    return [201, ''];
                });
            }

            protected function generateDanfse(string $authorizedXml): string
            {
                throw new \RuntimeException('Bundled Liberation Sans font missing.');
            }
        };

        try {
            (new StoreIssuedNfseArtifacts((int) $invoice->id, (int) $receipt->id))->handle($store, new PostEmissionState());
            self::fail('Expected the font failure to propagate to the queue.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Bundled Liberation Sans font missing.', $exception->getMessage());
        }

        self::assertSame([], $writes);
        self::assertEmpty($receipt->fresh()->danfse_webdav_path);
        self::assertSame('retrying', $receipt->payload()->value('artifacts_status'));
        self::assertSame('<NFSe>synthetic authorization</NFSe>', $receipt->payload()->value('authorized_xml'));
    }

    public function testExistingHistoricalDanfseIsNeverRegenerated(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '301',
            'chave_acesso' => str_repeat('4', 50),
            'danfse_webdav_path' => 'history/previously-approved.pdf',
            'status' => 'emitted',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe>synthetic historical authorization</NFSe>',
        ]);
        $writes = [];
        $store = self::fakeWebDavStore($writes);

        $store->store((int) $invoice->id, (int) $receipt->id);

        self::assertSame('history/previously-approved.pdf', $receipt->fresh()->danfse_webdav_path);
        self::assertCount(1, $writes);
        self::assertStringEndsWith('.xml', $writes[0][0]);
        self::assertSame('<NFSe>synthetic historical authorization</NFSe>', $writes[0][1]);
    }

    /**
     * @param array<int, array{string, string}> $writes
     */
    private static function fakeWebDavStore(array &$writes): IssuedNfseArtifactStore
    {
        return new class ($writes) extends IssuedNfseArtifactStore {
            public function __construct(private array &$writes)
            {
            }

            protected function webDavEnabled(): bool
            {
                return true;
            }

            protected function makeWebDavClient(): WebDavClient
            {
                return new WebDavClient(baseUrl: 'https://dav.example.test/root', request: function (string $method, string $url, array $headers, string $body): array {
                    if ($method === 'PUT') {
                        $this->writes[] = [$url, $body];
                    }

                    return [201, ''];
                });
            }
        };
    }

    private static function assertScopedPdf(string $pdf, string $expectedName): void
    {
        self::assertStringStartsWith('%PDF-', $pdf);
        $path = tempnam(sys_get_temp_dir(), 'nfse-scoped-pdf-');
        self::assertIsString($path);

        try {
            self::assertSame(strlen($pdf), file_put_contents($path, $pdf));
            $info = self::poppler('pdfinfo', $path);
            $fonts = self::poppler('pdffonts', $path);
            $text = self::poppler('pdftotext -layout', $path, ' -');

            self::assertMatchesRegularExpression('/^Pages:\s*1\s*$/m', $info);
            self::assertMatchesRegularExpression('/^Page size:\s*595\.28 x 841\.89 pts \(A4\)/m', $info);
            foreach (['LiberationSans', 'LiberationSans-Bold'] as $font) {
                $pattern = '/^(?:[A-Z]{6}\+)?' . preg_quote($font, '/') . '\s+.+\s+yes\s+(?:yes|no)\s+(?:yes|no)\s+\d+\s+\d+\s*$/m';
                self::assertMatchesRegularExpression($pattern, $fonts, $font . ' must be embedded.');
            }
            self::assertDoesNotMatchRegularExpression('/\b(?:Helvetica|Times|Courier)\b/', $fonts);
            $normalized = preg_replace('/\s+/u', ' ', $text);
            self::assertIsString($normalized);
            self::assertStringContainsString($expectedName, $normalized);
        } finally {
            unlink($path);
        }
    }

    private static function poppler(string $command, string $file, string $suffix = ''): string
    {
        $output = [];
        $status = 0;
        exec($command . ' ' . escapeshellarg($file) . $suffix . ' 2>&1', $output, $status);
        self::assertSame(0, $status, $command . ' failed: ' . implode("\n", $output));

        return implode("\n", $output);
    }
}
