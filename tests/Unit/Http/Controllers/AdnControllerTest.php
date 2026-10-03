<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Modules\Nfse\Http\Controllers\AdnController;
use Modules\Nfse\Tests\TestCase;

final class AdnControllerTest extends TestCase
{
    public function testDistributionNormalizesInputsAndReturnsOfficialData(): void
    {
        $controller = new class () extends AdnController {
            /** @var array{nsu:int, cnpj:?string, lote:bool}|null */
            public ?array $received = null;

            protected function fetchAdnDistribution(int $nsu, ?string $cnpj, bool $lote): array
            {
                $this->received = [
                    'nsu' => $nsu,
                    'cnpj' => $cnpj,
                    'lote' => $lote,
                ];

                return [
                    'status_processamento' => 'DOCUMENTOS_LOCALIZADOS',
                    'ultimo_nsu' => 12,
                    'documents' => [
                        ['nsu' => 11, 'tipo_documento' => 'NFSE'],
                    ],
                ];
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->distribution(new \Illuminate\Http\Request([
            'nsu' => '10',
            'cnpj' => '12abc34501de35',
            'lote' => '0',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'nsu' => 10,
            'cnpj' => '12ABC34501DE35',
            'lote' => false,
        ], $controller->received);
        self::assertSame(12, $response->getData(true)['data']['ultimo_nsu'] ?? null);
    }

    public function testReconcileDistributionMatchesLocalReceiptsWithoutMutatingState(): void
    {
        $controller = new class () extends AdnController {
            /** @var list<string> */
            public array $receivedAccessKeys = [];

            /**
             * @param array<string, mixed> $distribution
             * @return array<string, mixed>
             */
            public function reconcile(array $distribution): array
            {
                return $this->reconcileDistribution($distribution);
            }

            /**
             * @param list<string> $accessKeys
             * @return array<string, array{invoice_id:int, nfse_number:string, status:string}>
             */
            protected function localReceiptMatches(array $accessKeys): array
            {
                $this->receivedAccessKeys = $accessKeys;

                return [
                    'KEY-1' => [
                        'invoice_id' => 42,
                        'nfse_number' => '1001',
                        'status' => 'emitted',
                    ],
                ];
            }
        };

        $result = $controller->reconcile([
            'documents' => [
                ['nsu' => 1, 'chave_acesso' => 'KEY-1', 'tipo_documento' => 'NFSE'],
                ['nsu' => 2, 'chave_acesso' => 'KEY-2', 'tipo_documento' => 'NFSE'],
                ['nsu' => 3, 'chave_acesso' => null, 'tipo_documento' => 'EVENTO'],
            ],
        ]);

        self::assertSame(['KEY-1', 'KEY-2'], $controller->receivedAccessKeys);
        self::assertSame([
            'matched' => 1,
            'unmatched' => 2,
        ], $result['reconciliation'] ?? null);

        self::assertSame(
            [
                'invoice_id' => 42,
                'nfse_number' => '1001',
                'status' => 'emitted',
            ],
            $result['documents'][0]['local_receipt'] ?? null,
        );
        self::assertNull($result['documents'][1]['local_receipt'] ?? null);
        self::assertNull($result['documents'][2]['local_receipt'] ?? null);
    }

    public function testReconcileDistributionDeduplicatesAccessKeysBeforeDatabaseLookup(): void
    {
        $controller = new class () extends AdnController {
            /** @var list<string> */
            public array $receivedAccessKeys = [];

            /**
             * @param array<string, mixed> $distribution
             * @return array<string, mixed>
             */
            public function reconcile(array $distribution): array
            {
                return $this->reconcileDistribution($distribution);
            }

            protected function localReceiptMatches(array $accessKeys): array
            {
                $this->receivedAccessKeys = $accessKeys;

                return [];
            }
        };

        $controller->reconcile([
            'documents' => [
                ['chave_acesso' => 'KEY-1'],
                ['chave_acesso' => 'KEY-1'],
                ['chave_acesso' => ''],
            ],
        ]);

        self::assertSame(['KEY-1'], $controller->receivedAccessKeys);
    }

    public function testDistributionRejectsInvalidNsuBeforeAdnQuery(): void
    {
        $controller = new class () extends AdnController {
            public bool $queried = false;

            protected function fetchAdnDistribution(int $nsu, ?string $cnpj, bool $lote): array
            {
                $this->queried = true;

                return [];
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->distribution(new \Illuminate\Http\Request([
            'nsu' => '-1',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($controller->queried);
    }

    public function testDistributionRejectsInvalidCnpjBeforeAdnQuery(): void
    {
        $controller = new class () extends AdnController {
            public bool $queried = false;

            protected function fetchAdnDistribution(int $nsu, ?string $cnpj, bool $lote): array
            {
                $this->queried = true;

                return [];
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->distribution(new \Illuminate\Http\Request([
            'nsu' => '0',
            'cnpj' => 'invalid',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($controller->queried);
    }

    public function testXmlSignatureIntegrityClassifiesMissingAndUnsignedXml(): void
    {
        $controller = new class () extends AdnController {
            public function integrity(?string $xml): ?string
            {
                return $this->xmlSignatureIntegrity($xml);
            }
        };

        self::assertNull($controller->integrity(null));
        self::assertNull($controller->integrity('   '));
        self::assertSame('not_present', $controller->integrity('<NFSe><infNFSe/></NFSe>'));
    }

    public function testXmlSignatureIntegrityRejectsMalformedSignature(): void
    {
        $controller = new class () extends AdnController {
            public function integrity(?string $xml): ?string
            {
                return $this->xmlSignatureIntegrity($xml);
            }
        };

        self::assertSame(
            'invalid',
            $controller->integrity('<NFSe><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></NFSe>'),
        );
    }

    public function testEventsReturnsReadOnlyAdnDiagnosticsForReceiptAccessKey(): void
    {
        $invoice = new Invoice();
        $invoice->id = 42;

        $controller = new class () extends AdnController {
            public string $receivedAccessKey = '';

            protected function receiptAccessKey(Invoice $invoice): string
            {
                return 'ACCESS-42';
            }

            protected function fetchAdnEvents(string $accessKey): array
            {
                $this->receivedAccessKey = $accessKey;

                return [
                    'status_processamento' => 'DOCUMENTOS_LOCALIZADOS',
                    'documents' => [
                        [
                            'nsu' => 9,
                            'chave_acesso' => $accessKey,
                            'tipo_documento' => 'EVENTO',
                            'tipo_evento' => '101101',
                            'data_hora_geracao' => '2026-10-03T08:00:00-03:00',
                            'xml' => '<evento/>',
                        ],
                    ],
                ];
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->events($invoice);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ACCESS-42', $controller->receivedAccessKey);
        self::assertSame(
            '101101',
            $response->getData(true)['data']['documents'][0]['tipo_evento'] ?? null,
        );
    }

    public function testEventsReturnsNotFoundWithoutLocalReceiptAccessKey(): void
    {
        $invoice = new Invoice();
        $invoice->id = 43;

        $controller = new class () extends AdnController {
            public bool $queried = false;

            protected function receiptAccessKey(Invoice $invoice): string
            {
                return '';
            }

            protected function fetchAdnEvents(string $accessKey): array
            {
                $this->queried = true;

                return [];
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->events($invoice);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($controller->queried);
    }

    public function testEventsConvertsUpstreamFailureIntoStableGatewayResponse(): void
    {
        $invoice = new Invoice();
        $invoice->id = 44;

        $controller = new class () extends AdnController {
            protected function receiptAccessKey(Invoice $invoice): string
            {
                return 'ACCESS-44';
            }

            protected function fetchAdnEvents(string $accessKey): array
            {
                throw new \RuntimeException('sensitive upstream detail');
            }

            protected function jsonResponse(array $payload, int $status = 200): JsonResponse
            {
                return new JsonResponse($payload, $status);
            }
        };

        $response = $controller->events($invoice);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString(
            'sensitive upstream detail',
            (string) json_encode($response->getData(true)),
        );
    }
}
