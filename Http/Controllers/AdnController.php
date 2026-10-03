<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfseRuntimeContextFactory;
use Modules\Nfse\Support\TransportCertificateManager;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\AdnEnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

class AdnController extends Controller
{
    public function events(Invoice $invoice): JsonResponse
    {
        $accessKey = $this->receiptAccessKey($invoice);

        if ($accessKey === '') {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.events_missing_receipt'),
            ], 404);
        }

        try {
            return $this->jsonResponse([
                'data' => $this->fetchAdnEvents($accessKey),
            ]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.events_query_failed'),
            ], 502);
        }
    }

    protected function receiptAccessKey(Invoice $invoice): string
    {
        $receipt = NfseReceipt::query()
            ->where('invoice_id', (int) $invoice->id)
            ->first();

        return trim((string) ($receipt?->chave_acesso ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAdnEvents(string $accessKey): array
    {
        $cnpj = trim((string) setting('nfse.cnpj_prestador', ''));

        if ($cnpj === '') {
            throw new \RuntimeException('Service provider CNPJ is not configured.');
        }

        $secretStore = $this->makeSecretStore();
        $baseCert = new CertConfig(
            cnpj: $cnpj,
            pfxPath: storage_path('app/nfse/pfx/' . $cnpj . '.pfx'),
            vaultPath: 'pfx/' . $cnpj,
        );
        $context = $this->makeRuntimeContextFactory()->create($baseCert, $secretStore);

        try {
            $result = (new AdnClient(
                environment: new AdnEnvironmentConfig(sandboxMode: $this->sandboxModeEnabled()),
                cert: $context->cert,
            ))->listEvents($accessKey);

            return $this->normalizeDistribution($result);
        } finally {
            ($context->cleanup)();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeDistribution(AdnDistributionData $result): array
    {
        return [
            'status_processamento' => $result->statusProcessamento,
            'ambiente' => $result->ambiente,
            'versao_aplicativo' => $result->versaoAplicativo,
            'data_hora_processamento' => $result->dataHoraProcessamento,
            'ultimo_nsu' => $result->ultimoNsu,
            'documents' => array_map(
                static fn ($document): array => [
                    'nsu' => $document->nsu,
                    'chave_acesso' => $document->chaveAcesso,
                    'tipo_documento' => $document->tipoDocumento,
                    'tipo_evento' => $document->tipoEvento,
                    'data_hora_geracao' => $document->dataHoraGeracao,
                    'xml' => $document->xml,
                ],
                $result->documents,
            ),
            'alerts' => array_map(
                static fn ($message): array => [
                    'code' => $message->code,
                    'description' => $message->description,
                    'complement' => $message->complement,
                    'parameters' => $message->parameters,
                ],
                $result->alerts,
            ),
            'errors' => array_map(
                static fn ($message): array => [
                    'code' => $message->code,
                    'description' => $message->description,
                    'complement' => $message->complement,
                    'parameters' => $message->parameters,
                ],
                $result->errors,
            ),
        ];
    }

    protected function makeRuntimeContextFactory(): NfseRuntimeContextFactory
    {
        return new NfseRuntimeContextFactory(new TransportCertificateManager());
    }

    protected function makeSecretStore(): SecretStoreInterface
    {
        $config = VaultConfig::secretStoreConfig();

        return new OpenBaoSecretStore(
            addr: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }

    protected function sandboxModeEnabled(): bool
    {
        $value = setting('nfse.sandbox_mode', true);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }
}
