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
