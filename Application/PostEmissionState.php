<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;

final class PostEmissionState
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = ['pending', 'processing', 'retrying'];

    public function initialize(int $receiptId, bool $emailRequested): void
    {
        $payload = $this->payloadForReceiptId($receiptId);

        $payload->update([
            'artifacts_status' => 'pending',
            'email_status' => $emailRequested ? 'pending' : 'not_requested',
            'post_processing_error' => null,
            'artifacts_completed_at' => null,
            'post_emission_email_sent_at' => null,
        ]);
    }

    public function markArtifactsProcessing(int $receiptId): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'artifacts_status' => 'processing',
            'post_processing_error' => null,
        ]);
    }

    public function markArtifactsRetrying(int $receiptId, string $message): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'artifacts_status' => 'retrying',
            'post_processing_error' => $this->normalizeError($message),
        ]);
    }

    public function markArtifactsCompleted(int $receiptId): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'artifacts_status' => 'completed',
            'artifacts_completed_at' => now(),
            'post_processing_error' => null,
        ]);
    }

    public function markArtifactsFailed(int $receiptId, string $message): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'artifacts_status' => 'failed',
            'post_processing_error' => $this->normalizeError($message),
        ]);
    }

    public function markEmailProcessing(int $receiptId): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'email_status' => 'processing',
            'post_processing_error' => null,
        ]);
    }

    public function markEmailCompleted(int $receiptId): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'email_status' => 'completed',
            'post_emission_email_sent_at' => now(),
            'post_processing_error' => null,
        ]);
    }

    public function markEmailFailed(int $receiptId, string $message): void
    {
        $this->payloadForReceiptId($receiptId)->update([
            'email_status' => 'failed',
            'post_processing_error' => $this->normalizeError($message),
        ]);
    }

    public function markDispatchFailed(int $receiptId, bool $emailRequested, string $message): void
    {
        $values = [
            'artifacts_status' => 'failed',
            'post_processing_error' => $this->normalizeError($message),
        ];

        if ($emailRequested) {
            $values['email_status'] = 'failed';
        }

        $this->payloadForReceiptId($receiptId)->update($values);
    }

    /**
     * @return array{
     *   overall_status:string,
     *   poll:bool,
     *   artifacts_status:?string,
     *   email_status:?string,
     *   error:?string
     * }
     */
    public function snapshot(NfseReceipt $receipt): array
    {
        $payload = null;

        if (
            method_exists($receipt, 'relationLoaded')
            && method_exists($receipt, 'getRelation')
            && $receipt->relationLoaded('payload')
        ) {
            $payload = $receipt->getRelation('payload');
        } elseif (method_exists($receipt, 'payload')) {
            $relation = $receipt->payload();

            if (is_object($relation) && method_exists($relation, 'first')) {
                $payload = $relation->first();
            }
        }

        if (!$payload instanceof NfseReceiptPayload) {
            return [
                'overall_status' => 'idle',
                'poll' => false,
                'artifacts_status' => null,
                'email_status' => null,
                'error' => null,
            ];
        }

        $artifactsStatus = $this->normalizeStatus($payload->artifacts_status);
        $emailStatus = $this->normalizeStatus($payload->email_status);
        $failed = $artifactsStatus === 'failed' || $emailStatus === 'failed';
        $active = in_array($artifactsStatus, self::ACTIVE_STATUSES, true)
            || in_array($emailStatus, self::ACTIVE_STATUSES, true);
        $completed = $artifactsStatus === 'completed'
            && in_array($emailStatus, ['completed', 'not_requested'], true);

        return [
            'overall_status' => $failed ? 'failed' : ($active ? 'processing' : ($completed ? 'completed' : 'idle')),
            'poll' => !$failed && $active,
            'artifacts_status' => $artifactsStatus,
            'email_status' => $emailStatus,
            'error' => is_string($payload->post_processing_error)
                ? trim($payload->post_processing_error) ?: null
                : null,
        ];
    }

    private function payloadForReceiptId(int $receiptId): NfseReceiptPayload
    {
        $receipt = NfseReceipt::query()->findOrFail($receiptId);

        return $receipt->payload()->firstOrCreate();
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        $normalized = trim($status);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeError(string $message): string
    {
        return mb_substr(trim($message), 0, 2000);
    }
}
