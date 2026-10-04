<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlSignatureVerifier;

class AdnController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('nfse::adn.index');
    }

    public function distribution(Request $request): JsonResponse
    {
        $rawNsu = $request->query('nsu', 0);
        if (!is_numeric($rawNsu) || (int) $rawNsu < 0) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_nsu'),
            ], 422);
        }

        $cnpj = strtoupper(preg_replace(
            '/[^A-Z0-9]/i',
            '',
            (string) $request->query('cnpj', setting('nfse.cnpj_prestador', '')),
        ) ?? '');

        if ($cnpj !== '' && preg_match('/^[A-Z0-9]{12}\\d{2}$/', $cnpj) !== 1) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.invalid_cnpj'),
            ], 422);
        }

        $loteRaw = strtolower(trim((string) $request->query('lote', '1')));
        $lote = in_array($loteRaw, ['1', 'true', 'on', 'yes'], true);

        try {
            return $this->jsonResponse([
                'data' => $this->fetchAdnDistribution((int) $rawNsu, $cnpj !== '' ? $cnpj : null, $lote),
            ]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'message' => trans('nfse::general.adn.distribution_query_failed'),
            ], 502);
        }
    }

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
            ->latest('id')
            ->first();

        return trim((string) ($receipt?->chave_acesso ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAdnEvents(string $accessKey): array
    {
        return $this->withAdnClient(
            fn (AdnClient $client): array => $this->normalizeDistribution($client->listEvents($accessKey)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAdnDistribution(int $nsu, ?string $cnpj, bool $lote): array
    {
        return $this->withAdnClient(
            fn (AdnClient $client): array => $this->reconcileDistribution(
                $this->normalizeDistribution($client->getDfe($nsu, $cnpj, $lote)),
            ),
        );
    }

    /**
     * @param array<string, mixed> $distribution
     * @return array<string, mixed>
     */
    protected function reconcileDistribution(array $distribution): array
    {
        $documents = is_array($distribution['documents'] ?? null)
            ? $distribution['documents']
            : [];

        $accessKeys = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $accessKey = trim((string) ($document['chave_acesso'] ?? ''));
            if ($accessKey !== '') {
                $accessKeys[$accessKey] = true;
            }
        }

        $matches = $this->localReceiptMatches(array_keys($accessKeys));
        $matched = 0;

        foreach ($documents as $index => $document) {
            if (!is_array($document)) {
                continue;
            }

            $accessKey = trim((string) ($document['chave_acesso'] ?? ''));
            $localReceipt = $accessKey !== '' ? ($matches[$accessKey] ?? null) : null;

            if ($localReceipt !== null) {
                $matched++;
            }

            $documents[$index]['local_receipt'] = $localReceipt;
        }

        $distribution['documents'] = $documents;
        $distribution['reconciliation'] = [
            'matched' => $matched,
            'unmatched' => max(0, count($documents) - $matched),
        ];

        return $distribution;
    }

    /**
     * @param list<string> $accessKeys
     * @return array<string, array{invoice_id:int, nfse_number:string, status:string}>
     */
    protected function localReceiptMatches(array $accessKeys): array
    {
        if ($accessKeys === []) {
            return [];
        }

        $rows = NfseReceipt::query()
            ->whereIn('chave_acesso', $accessKeys)
            ->get(['invoice_id', 'chave_acesso', 'nfse_number', 'status']);

        $matches = [];

        foreach ($rows as $receipt) {
            $accessKey = trim((string) ($receipt->chave_acesso ?? ''));
            if ($accessKey === '') {
                continue;
            }

            $matches[$accessKey] = [
                'invoice_id' => (int) ($receipt->invoice_id ?? 0),
                'nfse_number' => (string) ($receipt->nfse_number ?? ''),
                'status' => (string) ($receipt->status ?? ''),
            ];
        }

        return $matches;
    }

    /**
     * @template T
     * @param \Closure(AdnClient): T $operation
     * @return T
     */
    protected function withAdnClient(\Closure $operation): mixed
    {
        $context = $this->makeFiscalClientFactory()->adn($this->sandboxModeEnabled());

        try {
            return $operation($context->adnClient());
        } finally {
            $context->close();
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
                fn ($document): array => [
                    'nsu' => $document->nsu,
                    'chave_acesso' => $document->chaveAcesso,
                    'tipo_documento' => $document->tipoDocumento,
                    'tipo_evento' => $document->tipoEvento,
                    'data_hora_geracao' => $document->dataHoraGeracao,
                    'signature_integrity' => $this->xmlSignatureIntegrity($document->xml),
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

    protected function xmlSignatureIntegrity(?string $xml): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        if (preg_match('/<(?:[A-Za-z0-9_.-]+:)?Signature\\b/', $xml) !== 1) {
            return 'not_present';
        }

        return (new XmlSignatureVerifier())->verify($xml)
            ? 'valid'
            : 'invalid';
    }

    protected function makeFiscalClientFactory(): FiscalClientFactory
    {
        return app(FiscalClientFactory::class);
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
