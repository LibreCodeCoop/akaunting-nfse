<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;

final class DeterministicFiscalTransport implements HttpTransportInterface
{
    public function request(HttpRequestData $request): HttpResponseData
    {
        $path = (string) (parse_url($request->url, PHP_URL_PATH) ?? '');

        if (str_contains($path, '/DFe/')) {
            return $this->json([
                'StatusProcessamento' => 'PROCESSADO',
                'TipoAmbiente' => '2',
                'VersaoAplicativo' => 'deterministic-test',
                'DataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'UltimoNSU' => 0,
                'LoteDFe' => [],
                'Alertas' => [],
                'Erros' => [],
            ]);
        }

        if (str_contains($path, '/Eventos')) {
            return $this->json([
                'StatusProcessamento' => 'PROCESSADO',
                'TipoAmbiente' => '2',
                'VersaoAplicativo' => 'deterministic-test',
                'DataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'LoteDFe' => [],
                'Alertas' => [],
                'Erros' => [],
            ]);
        }

        if (str_contains($path, '/parametrizacao/')) {
            return $this->json([
                'deterministic' => true,
            ]);
        }

        throw new \RuntimeException(
            'Deterministic fiscal transport has no fixture for ' . strtoupper($request->method) . ' ' . $path,
        );
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): HttpResponseData
    {
        return new HttpResponseData(
            status: 200,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
