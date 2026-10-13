<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Nfse\Application\IssuedNfseArtifactStore;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Jobs\StoreIssuedNfseArtifacts;
use Modules\Nfse\Tests\Support\FiscalScenarioBuilder;
use Tests\Feature\FeatureTestCase;

final class ScopedDanfseArtifactRecoveryTest extends FeatureTestCase
{
    public function testStoredScopedPdfCanBeRecoveredByteForByteWhenAuthorizedXmlIsUnavailable(): void
    {
        $this->loginAs();
        $invoice = FiscalScenarioBuilder::invoice();
        $receipt = FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'emitted',
            number: '9901',
            accessKey: str_repeat('9', 50),
        );

        $xml = file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);
        $receipt->payload()->create([
            'authorized_xml' => $xml,
            'artifacts_status' => 'pending',
            'email_status' => 'not_requested',
        ]);

        setting()->set([
            'nfse.webdav_url' => 'https://dav.example.test/root',
            'nfse.webdav_store_xml' => true,
            'nfse.webdav_store_pdf' => true,
            'nfse.cnpj_prestador' => '99999999000199',
        ]);
        setting()->save();

        /** @var array<string, string> $remoteFiles */
        $remoteFiles = [];
        /** @var list<string> $httpMethods */
        $httpMethods = [];
        Http::fake(static function (Request $request) use (&$remoteFiles, &$httpMethods) {
            $method = $request->method();
            $url = $request->url();
            $httpMethods[] = $method;

            if (!str_starts_with($url, 'https://dav.example.test/root/')) {
                return Http::response('Unexpected outbound request', 599);
            }

            if ($method === 'PUT') {
                $remoteFiles[$url] = $request->body();

                return Http::response('', 201);
            }

            if ($method === 'GET') {
                return isset($remoteFiles[$url])
                    ? Http::response($remoteFiles[$url], 200)
                    : Http::response('Not found', 404);
            }

            return Http::response('', 405);
        });

        $job = new StoreIssuedNfseArtifacts((int) $invoice->id, (int) $receipt->id);
        $job->handle(new IssuedNfseArtifactStore(), new PostEmissionState());

        $storedReceipt = $receipt->fresh();
        self::assertNotEmpty($storedReceipt->xml_webdav_path);
        self::assertNotEmpty($storedReceipt->danfse_webdav_path);
        self::assertSame('completed', $receipt->payload()->value('artifacts_status'));
        $pdfUrl = 'https://dav.example.test/root/' . implode('/', array_map(
            'rawurlencode',
            explode('/', (string) $storedReceipt->danfse_webdav_path),
        ));
        self::assertArrayHasKey($pdfUrl, $remoteFiles);
        $originalPdf = $remoteFiles[$pdfUrl];
        self::assertStringStartsWith('%PDF-', $originalPdf);

        // Model an unavailable authorized XML: the download must retrieve the
        // persisted binary, not trigger another fiscal emission or render.
        $receipt->payload()->update(['authorized_xml' => '']);

        $this->get(route('nfse.invoices.artifacts.download', [
            'invoice' => $invoice,
            'artifact' => 'danfse',
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertContent($originalPdf);

        self::assertSame(
            (string) $storedReceipt->danfse_webdav_path,
            (string) $receipt->fresh()->danfse_webdav_path,
        );
        self::assertSame($originalPdf, $remoteFiles[$pdfUrl]);
        self::assertSame(2, count(array_filter($httpMethods, static fn (string $method): bool => $method === 'PUT')));
        self::assertSame(1, count(array_filter($httpMethods, static fn (string $method): bool => $method === 'GET')));
        self::assertNotContains('POST', $httpMethods, 'Artifact recovery must not resend a fiscal request.');
    }
}
