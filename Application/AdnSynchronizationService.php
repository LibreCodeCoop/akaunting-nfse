<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\AdnSyncDocument;
use Modules\Nfse\Models\AdnSyncState;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDistributionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\AdnDocumentData;

final class AdnSynchronizationService
{
    private const EVENT_CANCELLATION = '101101';

    public function cursor(int $companyId, string $environment): int
    {
        $state = AdnSyncState::query()
            ->where('company_id', $companyId)
            ->where('environment', $environment)
            ->first();

        return max(0, (int) ($state?->last_nsu ?? 0));
    }

    /**
     * Persist one ADN batch atomically and return the committed cursor.
     */
    public function apply(int $companyId, string $environment, AdnDistributionData $distribution): int
    {
        return DB::transaction(function () use ($companyId, $environment, $distribution): int {
            $currentCursor = $this->cursor($companyId, $environment);
            $batchCursor = $currentCursor;

            foreach ($distribution->documents as $document) {
                $this->persistDocument($companyId, $environment, $document);

                if ($document->nsu !== null) {
                    $batchCursor = max($batchCursor, $document->nsu);
                }
            }

            if ($distribution->ultimoNsu !== null) {
                $batchCursor = max($batchCursor, $distribution->ultimoNsu);
            }

            AdnSyncState::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'environment' => $environment,
                ],
                [
                    'last_nsu' => $batchCursor,
                    'last_successful_at' => now(),
                    'last_error' => null,
                ],
            );

            return $batchCursor;
        });
    }

    public function markFailure(int $companyId, string $environment, \Throwable $error): void
    {
        AdnSyncState::updateOrCreate(
            [
                'company_id' => $companyId,
                'environment' => $environment,
            ],
            [
                'last_error' => mb_substr($error->getMessage(), 0, 2000),
            ],
        );
    }

    private function persistDocument(int $companyId, string $environment, AdnDocumentData $document): void
    {
        $eventType = trim((string) $document->tipoEvento);
        $accessKey = trim((string) $document->chaveAcesso);
        $recognizedEvent = $eventType === self::EVENT_CANCELLATION;
        $companyCnpj = strtoupper(preg_replace(
            '/[^A-Z0-9]/i',
            '',
            (string) setting('nfse.cnpj_prestador', ''),
        ) ?? '');
        $role = (new AdnImportCandidateClassifier())->classify(
            xml: $document->xml,
            companyDocument: $companyCnpj,
            documentType: (string) $document->tipoDocumento,
            eventType: $eventType,
        );

        $existing = AdnSyncDocument::query()
            ->where('company_id', $companyId)
            ->where('environment', $environment)
            ->where('document_key', $this->documentKey($document))
            ->first();

        $reviewStatus = in_array((string) ($existing?->review_status ?? ''), ['ignored', 'imported'], true)
            ? (string) $existing->review_status
            : (in_array($role, ['received', 'intermediated'], true) ? 'pending' : 'not_applicable');

        AdnSyncDocument::updateOrCreate(
            [
                'company_id' => $companyId,
                'environment' => $environment,
                'document_key' => $this->documentKey($document),
            ],
            [
                'nsu' => $document->nsu,
                'chave_acesso' => $accessKey !== '' ? $accessKey : null,
                'tipo_documento' => $document->tipoDocumento,
                'tipo_evento' => $eventType !== '' ? $eventType : null,
                'data_hora_geracao' => $document->dataHoraGeracao,
                'xml' => $document->xml,
                'recognized_event' => $recognizedEvent,
                'fiscal_role' => $role,
                'review_status' => $reviewStatus,
            ],
        );

        if ($recognizedEvent && $accessKey !== '') {
            NfseReceipt::query()
                ->where('chave_acesso', $accessKey)
                ->update(['status' => 'cancelled']);
        }
    }

    private function documentKey(AdnDocumentData $document): string
    {
        if ($document->nsu !== null) {
            return 'nsu:' . $document->nsu;
        }

        return 'sha256:' . hash('sha256', implode('|', [
            $document->tipoDocumento,
            (string) $document->chaveAcesso,
            (string) $document->tipoEvento,
            (string) $document->dataHoraGeracao,
            (string) $document->xml,
        ]));
    }
}
