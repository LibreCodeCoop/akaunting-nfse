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
        $operationKey = $this->operationKeyFor(
            $companyId,
            $invoiceId,
            $environment,
            $groupKey,
            $cancelledReceiptId,
            $substitutedReceiptId,
            $origin,
            $draft,
        );

        // Persisted reservations support read-only reconciliation. They never
        // grant another first POST without a fresh irreversible authority claim.
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
            // Keep historical reservations recoverable, but never allocate a
            // new DPS which the gateway must reject under E0713/E0712.
            $this->assertTaxTotalsForNewDps($draft);
            $reservation = (new DpsReservationService(
                $this->authorizer,
                $this->coordinator ?? $this->configuration->connect(),
            ))->reserve(
                $companyId,
                $invoiceId,
                $issuer,
                $environment,
                $series,
                $operationKey,
                $origin,
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
     * Read-only key selection. Must run after the issuer authorizer in prepare().
     * The caller may inspect it without allocating another fiscal number.
     */
    private function operationKeyFor(
        int $companyId,
        int $invoiceId,
        int $environment,
        ?string $groupKey,
        ?int $cancelledReceiptId,
        ?int $substitutedReceiptId,
        string $origin,
        DpsData $draft,
    ): string {
        $operationKey = DpsOperationKey::forInvoice(
            $companyId,
            $invoiceId,
            $environment,
            $groupKey,
            $cancelledReceiptId,
            $substitutedReceiptId,
        );

        if ($origin === 'substitution' && $substitutedReceiptId !== null) {
            // A definitive E1235/E0713 rejection consumed its central first-POST claim.
            // Replay with the same immutable reservation is correctly blocked.
            // A deliberate new substitution uses a NEW logical key and a NEW
            // coordinator number, while unconfirmed outcomes remain pinned.
            $baseOperationKey = $operationKey;
            $operationKey = (new RejectedSubstitutionOperationKey())->resolve(
                $baseOperationKey,
                function (string $key) use ($companyId, $invoiceId, $environment): ?array {
                    $reservation = DB::table('nfse_dps_reservations')
                        ->where('operation_key', $key)
                        ->first(['id', 'company_id', 'invoice_id', 'origin']);
                    if (!is_object($reservation)) {
                        return null;
                    }
                    if ((int) $reservation->company_id !== $companyId
                        || (int) $reservation->invoice_id !== $invoiceId
                        || (string) $reservation->origin !== 'substitution') {
                        throw new \LogicException('Fiscal reservation is not the selected substitution operation.');
                    }

                    $attempt = DB::table('nfse_emission_attempts')
                        ->where('dps_reservation_id', (int) $reservation->id)
                        ->where('company_id', $companyId)
                        ->where('invoice_id', $invoiceId)
                        ->where('environment', $environment)
                        ->where('origin', 'substitution')
                        ->orderByDesc('id')
                        ->first(['id', 'status', 'official_code', 'http_status']);

                    return is_object($attempt) ? [
                        'id' => (int) $attempt->id,
                        'status' => (string) $attempt->status,
                        'official_code' => $attempt->official_code,
                        'http_status' => $attempt->http_status,
                    ] : null;
                },
                fn (int $rejectedId): string => DpsOperationKey::forInvoice(
                    $companyId,
                    $invoiceId,
                    $environment,
                    $groupKey,
                    $cancelledReceiptId,
                    $substitutedReceiptId,
                    $rejectedId,
                ),
            );

            if (!hash_equals($baseOperationKey, $operationKey)) {
                $this->assertTaxTotalsForNewDps($draft);
                if ($draft->substituicao === null
                    || preg_match('/^\d{50}$/D', $draft->substituicao->chaveNfseSubstituida) !== 1) {
                    throw new FiscalIssuanceBlocked(
                        'NFSE-SUBSTITUTION-EVIDENCE: a correctable rejection requires the original authorized NFS-e reference.',
                    );
                }

                // Correction must be locally provable before a fresh
                // irreversible fiscal allocation or any transport request.
                $release = $environment === 2
                    ? \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig::SCHEMA_RELEASE_RESTRICTED_20261020
                    : \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig::SCHEMA_RELEASE_PRODUCTION_20260209;
                try {
                    $xml = (new \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlBuilder())
                        ->buildDps($draft, $release);
                    $errors = (new \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\DpsSchemaValidator(
                        schemaRelease: $release,
                    ))->validate($xml);
                } catch (\InvalidArgumentException $error) {
                    throw new FiscalIssuanceBlocked(
                        'NFSE-XML-SCHEMA-PREFLIGHT: the corrected DPS cannot be serialized in the active official schema.',
                        previous: $error,
                    );
                }
                if ($errors !== []) {
                    throw new FiscalIssuanceBlocked(
                        'NFSE-XML-SCHEMA-PREFLIGHT: the corrected DPS does not pass the active official XSD.',
                    );
                }
            }
        }

        return $operationKey;
    }

    /**
     * Require legitimate approximate-tax data before allocating a new DPS.
     */
    private function assertTaxTotalsForNewDps(DpsData $draft): void
    {
        (new DpsTaxTotalsPreflight())->assertForNewDps(
            (int) $draft->opcaoSimplesNacional,
            $draft->totalTributosPercentualFederal,
            $draft->totalTributosPercentualEstadual,
            $draft->totalTributosPercentualMunicipal,
        );
    }

    /**
     * Verify the immutable reservation before requesting an independent
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
            $companyId,
            $invoiceId,
            $issuer,
            (int) $row->environment,
            (string) $row->series,
        );
        ($this->coordinator ?? $this->configuration->connect())->claimFirstTransmission(
            $issuer,
            (int) $row->environment,
            (string) $row->series,
            trim((string) $row->coordination_reference),
            (string) $row->operation_key,
            (int) $row->number,
            $fingerprint,
        );
    }
}
