<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Nfse\Application\IssuedNfseArtifactStore;
use Modules\Nfse\Application\PostEmissionState;

final class StoreIssuedNfseArtifacts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $invoiceId,
        public readonly int $receiptId,
    ) {
        if ($invoiceId <= 0 || $receiptId <= 0) {
            throw new \InvalidArgumentException('Invoice and receipt identifiers are required.');
        }
    }

    public function handle(IssuedNfseArtifactStore $store, PostEmissionState $state): void
    {
        $state->markArtifactsProcessing($this->receiptId);

        try {
            $store->store($this->invoiceId, $this->receiptId);
            $state->markArtifactsCompleted($this->receiptId);
        } catch (\Throwable $throwable) {
            $state->markArtifactsRetrying($this->receiptId, $throwable->getMessage());

            throw $throwable;
        }
    }

    public function failed(?\Throwable $throwable): void
    {
        try {
            app(PostEmissionState::class)->markArtifactsFailed(
                $this->receiptId,
                $throwable?->getMessage() ?? 'NFS-e artifact processing failed.',
            );
        } catch (\Throwable) {
            // Never mask the original queue failure while recording observability state.
        }
    }
}
