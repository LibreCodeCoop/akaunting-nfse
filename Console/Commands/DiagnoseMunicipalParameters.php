<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use Illuminate\Console\Command;
use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\QueryException;

final class DiagnoseMunicipalParameters extends Command
{
    protected $signature = 'nfse:municipal-diagnose
        {company : Akaunting company ID}
        {service : 9-digit municipal service code}
        {competence : Competence date in YYYY-MM-DD format}
        {--production : Query the production ADN without changing saved settings}';

    protected $description = 'Diagnose official ADN municipal-parameter endpoints without the web UI.';

    public function handle(FiscalClientFactory $clients): int
    {
        $companyId = max(0, (int) $this->argument('company'));
        $serviceCode = preg_replace('/\\D+/', '', (string) $this->argument('service')) ?? '';
        $competence = trim((string) $this->argument('competence'));

        if ($companyId <= 0) {
            $this->error('A valid company ID is required.');

            return self::FAILURE;
        }

        if (preg_match('/^\\d{9}$/', $serviceCode) !== 1) {
            $this->error('Service code must contain exactly 9 digits.');

            return self::FAILURE;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competence);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $competence) {
            $this->error('Competence must use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $previousCompanyId = function_exists('company_id') ? (int) company_id() : 0;

        try {
            company($companyId)->makeCurrent();

            $municipio = trim((string) setting('nfse.municipio_ibge', ''));
            $sandbox = $this->option('production')
                ? false
                : $this->booleanSetting(setting('nfse.sandbox_mode', true));

            if (preg_match('/^\\d{7}$/', $municipio) !== 1) {
                $this->error('Configured municipality IBGE code is invalid.');

                return self::FAILURE;
            }

            $context = $clients->municipalParameters($sandbox);

            try {
                $client = $context->municipalParametersClient();

                $result = [
                    'company_id' => $companyId,
                    'municipio_ibge' => $municipio,
                    'service_code' => $serviceCode,
                    'competence' => $competence,
                    'environment' => $sandbox ? 'sandbox' : 'production',
                    'endpoints' => [
                        'convenio' => $this->capture(fn (): array => $client->convenio($municipio)),
                        'aliquota' => $this->capture(fn (): array => $client->aliquota($municipio, $serviceCode, $competence)),
                        'regimes_especiais' => $this->capture(fn (): array => $client->regimesEspeciais($municipio, $serviceCode, $competence)),
                        'retencoes' => $this->capture(fn (): array => $client->retencoes($municipio, $competence)),
                    ],
                ];

                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            } finally {
                $context->close();
            }
        } catch (\Throwable $error) {
            $this->error($error::class . ': ' . $error->getMessage());

            return self::FAILURE;
        } finally {
            if ($previousCompanyId > 0 && $previousCompanyId !== $companyId) {
                company($previousCompanyId)->makeCurrent();
            }
        }
    }

    /**
     * @param callable(): array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function capture(callable $operation): array
    {
        try {
            return [
                'status' => 'ok',
                'http_status' => 200,
                'payload' => $operation(),
            ];
        } catch (QueryException $error) {
            return [
                'status' => 'upstream_error',
                'http_status' => $error->httpStatus,
                'payload' => $error->upstreamPayload,
            ];
        } catch (\Throwable $error) {
            return [
                'status' => 'error',
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];
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
