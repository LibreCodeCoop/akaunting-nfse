<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\XmlSignerInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NfseClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\NoOpSecretStore;

/**
 * The scoped Composer runtime, not only this module's mocks, must understand
 * the actual SEFIN 201 receipt shape before the module can be deployed.
 */
final class NfsePhpCurrentIssuanceContractTest extends TestCase
{
    public function testScopedRuntimeParsesOfficialIssuanceXmlAndDoesNotRepeatPost(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);
        $payload = json_encode([
            'tipoAmbiente' => 2,
            'versaoAplicativo' => 'SEFIN',
            'dataHoraProcessamento' => '2026-10-09T10:00:00-03:00',
            'idDps' => 'DPS-123',
            'chaveAcesso' => str_repeat('8', 50),
            'nfseXmlGZipB64' => base64_encode(gzencode($xml)),
        ], JSON_THROW_ON_ERROR);

        [$client, $transport] = $this->client($payload, 201);
        $receipt = $client->emit($this->dps());

        self::assertSame('10', $receipt->nfseNumber);
        self::assertSame(str_repeat('8', 50), $receipt->chaveAcesso);
        self::assertSame($xml, $receipt->rawXml);
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]->method);
        self::assertStringEndsWith('/SefinNacional/nfse', $transport->requests[0]->url);
    }

    public function testScopedRuntimeNeverAuthorizesIncompleteSuccessBody(): void
    {
        [$client, $transport] = $this->client(json_encode([
            'erros' => [['codigo' => 'E0312', 'descricao' => 'Municipio nao administra servico']],
        ], JSON_THROW_ON_ERROR), 200);

        try {
            $client->emit($this->dps());
            self::fail('A successful HTTP code alone is not fiscal authorization.');
        } catch (NetworkException $error) {
            self::assertSame(NfseErrorCode::InvalidResponse, $error->errorCode);
        }

        self::assertCount(1, $transport->requests);
    }

    /**
     * @return array{NfseClient, HttpTransportInterface&object}
     */
    private function client(string $body, int $status): array
    {
        $transport = new class ($body, $status) implements HttpTransportInterface {
            /** @var list<HttpRequestData> */
            public array $requests = [];

            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function request(HttpRequestData $request): HttpResponseData
            {
                $this->requests[] = $request;

                return new HttpResponseData(status: $this->status, body: $this->body);
            }
        };
        $signer = new class () implements XmlSignerInterface {
            public function sign(string $xml, string $cnpj): string
            {
                return $xml;
            }
        };
        $client = new NfseClient(
            environment: new EnvironmentConfig(sandboxMode: true),
            cert: new CertConfig(
                cnpj: '11222333000181',
                pfxPath: '/dev/null',
                vaultPath: 'test',
            ),
            secretStore: new NoOpSecretStore(),
            signer: $signer,
            transport: $transport,
        );

        return [$client, $transport];
    }

    private function dps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '5.00',
            discriminacao: 'Testes de contrato NFS-e vigente',
            codigoTributacaoNacional: '010701',
            tipoAmbiente: 2,
        );
    }
}
