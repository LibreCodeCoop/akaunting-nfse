<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Support\NfseCompanyProfileCatalog;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * The single bridge from a persisted fiscal operation to the existing DPS
 * allocator. HTTP, workers, groups, batches and substitutions use one policy.
 */
final class DpsIssuanceIdentity implements DpsIssuanceIdentityContract
{
    public function __construct(
        private readonly DpsReservationAuthorizer $authorizer = new CompanyProfileDpsReservationAuthorizer(),
        private readonly ConfiguredDpsCoordinator $configuration = new ConfiguredDpsCoordinator(),
        private readonly RuntimeDpsFactory $runtime = new RuntimeDpsFactory(),
        private readonly ?DpsSeriesCoordinator $coordinator = null,
    ) {
    }

    public static function normalizeSeries(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{1,5}$/D', $value) !== 1
            || (int) $value === 0 || (int) $value > 49999) {
            throw new \LogicException('An explicitly approved API DPS series from 00001 to 49999 is required.');
        }

        return str_pad((string) (int) $value, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{dps:DpsData,reservation_id:int,operation_key:string}
     */
    public function prepare(
        int $invoiceId,
        DpsData $draft,
        string $origin,
        ?string $groupKey = null,
        ?int $cancelledReceiptId = null,
        ?int $substitutedReceiptId = null,
    ): array {
        $invoice = DB::table('documents')->where('id', $invoiceId)
            ->where('type', 'invoice')->first(['id', 'company_id']);
        if (!is_object($invoice)) {
            throw new \LogicException('Fiscal invoice is not persisted.');
        }

        $companyId = (int) $invoice->company_id;
        $profile = (new NfseCompanyProfileCatalog())->requireActive($companyId);
        $fiscal = $profile->fiscal_parameters ?? [];
        $issuer = new DpsFiscalIssuer(
            (string) ($fiscal['municipio_ibge'] ?? ''),
            2,
            (string) ($profile->certificate_cnpj ?? ''),
        );
        $environment = (int) $draft->tipoAmbiente;
        $series = self::normalizeSeries($fiscal['dps_series'] ?? null);

        if ((string) $draft->municipioIbge !== $issuer->municipality
            || strtoupper((string) $draft->cnpjPrestador) !== $issuer->registration
            || $environment !== ((new \Modules\Nfse\Support\NfseCompanyIssuerPayload())->sandboxMode($fiscal) ? 2 : 1)) {
            throw new \LogicException('DPS draft does not match the persisted issuing fiscal profile.');
        }

        $this->authorizer->assertCanReserve($companyId, $invoiceId, $issuer, $environment, $series);
        $operationKey = DpsOperationKey::forInvoice(
            $companyId, $invoiceId, $environment, $groupKey, $cancelledReceiptId, $substitutedReceiptId,
        );

        // Locally persisted reservations can be used to reconcile during a
        // central outage. They never grant another first POST without a fresh
        // irrevocable global claim.
        $existing = DB::table('nfse_dps_reservations')
            ->where('operation_key', $operationKey)->first();
        if (is_object($existing)) {
            $counter = DB::table('nfse_dps_series')->where('id', $existing->series_id)->first();
            if (!is_object($counter)
                || (int) $existing->company_id !== $companyId
                || (int) $existing->invoice_id !== $invoiceId
                || (int) $counter->environment !== $environment
                || (string) $counter->issuer_municipality !== $issuer->municipality
                || (int) $counter->issuer_registration_type !== $issuer->registrationType
                || (string) $counter->issuer_registration !== $issuer->registration
                || (string) $counter->series !== $series) {
                throw new \LogicException('Persisted fiscal operation conflicts with the approved issuer or series.');
            }
            $reservation = [
                'reservation_id' => (int) $existing->id,
                'series' => (string) $counter->series,
                'number' => (string) $existing->number,
            ];
        } else {
            $reservation = (new DpsReservationService(
                $this->authorizer, $this->coordinator ?? $this->configuration->connect(),
            ))->reserve(
                $companyId, $invoiceId, $issuer, $environment, $series, $operationKey, $origin,
            );
        }

        $payload = get_object_vars($draft);
        $payload['serie'] = $reservation['series'];
        $payload['numeroDps'] = $reservation['number'];

        return [
            'dps' => $this->runtime->make($payload),
            'reservation_id' => (int) $reservation['reservation_id'],
            'operation_key' => $operationKey,
        ];
    }

    /**
     * Verify the immutable local relation before ever requesting a central
     * first-POST claim. This is also used by the journal under its invoice lock.
     */
    public function assertLocal(
        int $companyId,
        int $invoiceId,
        int $reservationId,
        DpsData $dps,
    ): object {
        $record = DB::table('nfse_dps_reservations as r')
            ->join('nfse_dps_series as s', 's.id', '=', 'r.series_id')
            ->where('r.id', $reservationId)->first([
                'r.company_id', 'r.invoice_id', 'r.number', 'r.operation_key',
                's.environment', 's.issuer_municipality', 's.issuer_registration_type',
                's.issuer_registration', 's.series', 's.coordination_reference', 's.coordination_mode',
            ]);

        if (!is_object($record)
            || (int) $record->company_id !== $companyId
            || (int) $record->invoice_id !== $invoiceId
            || (int) $record->environment !== (int) $dps->tipoAmbiente
            || (string) $record->issuer_municipality !== (string) $dps->municipioIbge
            || (int) $record->issuer_registration_type !== 2
            || (string) $record->issuer_registration !== strtoupper((string) $dps->cnpjPrestador)
            || (string) $record->series !== (string) $dps->serie
            || (string) $record->number !== (string) (int) $dps->numeroDps
            || (string) $record->coordination_mode !== 'shared_database') {
            throw new \LogicException('Attempt has no matching immutable, coordinated fiscal reservation.');
        }

        return $record;
    }

    public function claimFirstPost(int $companyId, int $invoiceId, int $reservationId, DpsData $dps, string $fingerprint): void
    {
        $row = $this->assertLocal($companyId, $invoiceId, $reservationId, $dps);
        $issuer = new DpsFiscalIssuer(
            (string) $row->issuer_municipality,
            (int) $row->issuer_registration_type,
            (string) $row->issuer_registration,
        );
        // Recheck actor/tenant/profile immediately before the irreversible claim.
        $this->authorizer->assertCanReserve(
            $companyId, $invoiceId, $issuer, (int) $row->environment, (string) $row->series,
        );
        ($this->coordinator ?? $this->configuration->connect())->claimFirstTransmission(
            $issuer, (int) $row->environment, (string) $row->series,
            trim((string) $row->coordination_reference), (string) $row->operation_key,
            (int) $row->number, $fingerprint,
        );
    }
}
