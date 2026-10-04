<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use Illuminate\Console\Command;
use Modules\Nfse\Application\AdnSynchronizationService;
use Modules\Nfse\Support\FiscalClientFactory;

final class SyncAdn extends Command
{
    protected $signature = 'nfse:adn-sync
        {company : Akaunting company ID}
        {--max-pages=10 : Maximum ADN batches to fetch in one run}';

    protected $description = 'Synchronize National NFS-e ADN documents and events safely.';

    public function handle(
        FiscalClientFactory $clients,
        AdnSynchronizationService $synchronization,
    ): int {
        $companyId = max(0, (int) $this->argument('company'));
        $maxPages = max(1, min(100, (int) $this->option('max-pages')));

        if ($companyId <= 0) {
            $this->error('A valid company ID is required.');

            return self::FAILURE;
        }

        $previousCompanyId = function_exists('company_id') ? (int) company_id() : 0;

        try {
            company($companyId)->makeCurrent();

            $sandbox = $this->booleanSetting(setting('nfse.sandbox_mode', true));
            $environment = $sandbox ? 'sandbox' : 'production';
            $cnpj = strtoupper(preg_replace(
                '/[^A-Z0-9]/i',
                '',
                (string) setting('nfse.cnpj_prestador', ''),
            ) ?? '');

            $context = $clients->adn($sandbox);

            try {
                $cursor = $synchronization->cursor($companyId, $environment);

                for ($page = 0; $page < $maxPages; ++$page) {
                    $distribution = $context->adnClient()->getDfe(
                        $cursor,
                        $cnpj !== '' ? $cnpj : null,
                        true,
                    );
                    $nextCursor = $synchronization->apply($companyId, $environment, $distribution);

                    if ($nextCursor <= $cursor || $distribution->documents === []) {
                        break;
                    }

                    $cursor = $nextCursor;
                }

                $this->info('ADN synchronization completed at NSU ' . $cursor . '.');

                return self::SUCCESS;
            } finally {
                $context->close();
            }
        } catch (\Throwable $error) {
            $environment = isset($environment) ? $environment : 'unknown';
            $synchronization->markFailure($companyId, $environment, $error);
            $this->error('ADN synchronization failed: ' . $error->getMessage());

            return self::FAILURE;
        } finally {
            if ($previousCompanyId > 0 && $previousCompanyId !== $companyId) {
                company($previousCompanyId)->makeCurrent();
            }
        }
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
