<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;

/**
 * Deterministic fiscal transport for browser/feature tests.
 *
 * This transport never performs network I/O. Any unrecognized request fails
 * loudly so test coverage cannot silently drift from the protocol operations
 * exercised by the module.
 */
final class DeterministicFiscalHttpTransport implements HttpTransportInterface
{
    #[\Override]
    public function request(HttpRequestData $request): HttpResponseData
    {
        $method = strtoupper($request->method);
        $path = (string) parse_url($request->url, PHP_URL_PATH);

        if ($method === 'POST' && preg_match('#/nfse$#i', $path) === 1) {
            return $this->json(201, $this->receiptPayload());
        }

        if ($method === 'GET' && preg_match('#/nfse/[^/]+$#i', $path) === 1) {
            return $this->json(200, $this->receiptPayload());
        }

        if ($method === 'HEAD' && preg_match('#/dps/[^/]+$#i', $path) === 1) {
            return new HttpResponseData(200, '');
        }

        if ($method === 'GET' && preg_match('#/dps/[^/]+$#i', $path) === 1) {
            return $this->json(200, ['chaveAcesso' => 'TEST-ACCESS-KEY-42']);
        }

        if ($method === 'POST' && preg_match('#/nfse/[^/]+/eventos$#i', $path) === 1) {
            return $this->json(200, [
                'tipoAmbiente' => 2,
                'versaoAplicativo' => 'fixture-1.0',
                'dataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
            ]);
        }

        if ($method === 'GET' && preg_match('#/nfse/[^/]+/eventos/\d{6}/\d+$#i', $path) === 1) {
            $xml = '<evento versao="1.01"><infEvento Id="EVT-TEST"/></evento>';

            return $this->json(200, [
                'tipoAmbiente' => 2,
                'versaoAplicativo' => 'fixture-1.0',
                'dataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'eventoXmlGZipB64' => $this->gzipBase64($xml),
            ]);
        }

        if ($method === 'GET' && preg_match('#/DFe/\d+$#i', $path) === 1) {
            return $this->json(200, [
                'StatusProcessamento' => 'PROCESSADO',
                'TipoAmbiente' => '2',
                'VersaoAplicativo' => 'fixture-1.0',
                'DataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'UltimoNSU' => 0,
                'LoteDFe' => [],
            ]);
        }

        if ($method === 'GET' && preg_match('#/NFSe/[^/]+/Eventos$#', $path) === 1) {
            return $this->json(200, [
                'StatusProcessamento' => 'PROCESSADO',
                'TipoAmbiente' => '2',
                'VersaoAplicativo' => 'fixture-1.0',
                'DataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
                'LoteDFe' => [],
            ]);
        }

        if ($method === 'GET' && preg_match('#/\d{7}/convenio$#', $path) === 1) {
            return $this->json(200, [
                'convenio' => true,
                'ambiente' => 'fixture',
            ]);
        }

        if ($method === 'GET' && preg_match('#/\d{7}/[^/]+/[^/]+/aliquota$#', $path) === 1) {
            return $this->json(200, ['aliquota' => '2.00']);
        }

        if ($method === 'GET' && preg_match('#/\d{7}/[^/]+/[^/]+/regimes_especiais$#', $path) === 1) {
            return $this->json(200, ['regimes' => []]);
        }

        if ($method === 'GET' && preg_match('#/\d{7}/[^/]+/retencoes$#', $path) === 1) {
            return $this->json(200, ['retencoes' => []]);
        }

        if ($method === 'GET' && preg_match('#/\d{7}/[^/]+/[^/]+/beneficio$#', $path) === 1) {
            return $this->json(200, ['beneficio' => null]);
        }

        throw new \RuntimeException(
            'Unexpected deterministic fiscal request: ' . $method . ' ' . $request->url
        );
    }

    /**
     * @return array<string, string>
     */
    private function receiptPayload(): array
    {
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<infNFSe Id="NFSE-TEST"><nNFSe>42</nNFSe></infNFSe>'
            . '</NFSe>';

        return [
            'nNFSe' => '42',
            'chaveAcesso' => 'TEST-ACCESS-KEY-42',
            'dataHoraProcessamento' => '2026-10-03T12:00:00-03:00',
            'codigoVerificacao' => 'TEST42',
            'nfseXmlGZipB64' => $this->gzipBase64($xml),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): HttpResponseData
    {
        return new HttpResponseData(
            status: $status,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function gzipBase64(string $value): string
    {
        $compressed = gzencode($value);

        if ($compressed === false) {
            throw new \RuntimeException('Unable to compress deterministic fiscal fixture.');
        }

        return base64_encode($compressed);
    }
}
