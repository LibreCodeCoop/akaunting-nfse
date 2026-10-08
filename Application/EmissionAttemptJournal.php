<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\GatewayException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

/**
 * Durable audit trail and fail-closed idempotency for a mutable DPS.
 *
 * Record pending BEFORE sending POST. On crash/timeout or persistence failure,
 * only reconcile by the existing immutable DPS recovery API. Never retry POST
 * merely because an HTTP job, a queue worker or a read-only query is repeated.
 */
final class EmissionAttemptJournal
{
    public const PENDING = 'pending';
    public const AUTHORIZED = 'authorized';
    public const REJECTED = 'rejected';
    public const AMBIGUOUS = 'ambiguous';
    public const TECHNICAL_FAILURE = 'technical_failure';

    public function __construct(
        private readonly RecoverInvoiceEmission $recovery = new RecoverInvoiceEmission(),
        private readonly OfficialIssuanceRejection $rejections = new OfficialIssuanceRejection(),
    ) {
    }

    /**
     * @param callable(ReceiptData):NfseReceipt $persist
     * @param (callable():ReceiptData)|null $transmit Uses IssueInvoiceNfse by default.
     * @return array{receipt:NfseReceipt,remote_receipt:?ReceiptData,reused:bool}
     */
    public function issue(
        NfseClientInterface $client,
        DpsData $dps,
        int $invoiceId,
        string $origin,
        callable $persist,
        ?string $groupKey = null,
        ?callable $transmit = null,
    ): array {
        $registration = $this->begin($invoiceId, $dps, $origin, $groupKey);
        $attempt = $registration['attempt'];

        if ((string) $attempt->status === self::AUTHORIZED) {
            $existing = NfseReceipt::query()
                ->whereKey((int) $attempt->receipt_id)
                ->where('invoice_id', $invoiceId)
                ->first();

            if (!$existing instanceof NfseReceipt) {
                throw new \LogicException('Authorized DPS receipt is missing. Reconcile manually; do not repeat POST.');
            }

            return ['receipt' => $existing, 'remote_receipt' => null, 'reused' => true];
        }

        $reconciled = $registration['recovery_only'];

        if ($reconciled) {
            $remote = $this->recoverSafely($client, $dps);

            if (!$remote instanceof ReceiptData) {
                $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'reconciliation']);

                throw new NetworkException('DPS result remains ambiguous; reconcile without repeating POST.');
            }
        } else {
            try {
                $remote = $transmit !== null
                    ? $transmit()
                    : (new IssueInvoiceNfse($this->recovery))->issue($client, $dps);
            } catch (GatewayException $exception) {
                $rejection = $this->rejections->fromException($exception);

                if ($rejection !== null) {
                    $this->transition($attempt, self::REJECTED, [
                        'official_code' => $rejection['code'],
                        'official_message' => $rejection['message'],
                        'http_status' => $rejection['http_status'],
                    ]);
                } else {
                    $this->transition($attempt, self::AMBIGUOUS, [
                        'failure_class' => 'gateway_unconfirmed',
                        'http_status' => $exception->httpStatus > 0 ? $exception->httpStatus : null,
                    ]);
                }

                throw $exception;
            } catch (NetworkException $exception) {
                $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'transport']);

                // IssueInvoiceNfse already tried DPS recovery. Leave any
                // unresolved result to a later read-only reconciliation.
                throw $exception;
            } catch (SecretStoreException|PfxImportException|\InvalidArgumentException|\LogicException $exception) {
                $this->transition($attempt, self::TECHNICAL_FAILURE, ['failure_class' => 'local_preflight']);

                throw $exception;
            } catch (\Throwable $exception) {
                // We cannot prove that an arbitrary failure occurred before POST.
                $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'unconfirmed']);

                throw $exception;
            }
        }

        try {
            return DB::transaction(function () use ($attempt, $remote, $invoiceId, $persist, $reconciled): array {
                $receipt = $persist($remote);

                if (!$receipt instanceof NfseReceipt || (int) $receipt->invoice_id !== $invoiceId) {
                    throw new \LogicException('Fiscal receipt persistence returned a different invoice.');
                }

                // Fiscal receipt and this link MUST commit or roll back together.
                $this->transition($attempt, self::AUTHORIZED, [
                    'receipt_id' => (int) $receipt->id,
                    'reconciled_at' => $reconciled ? now() : null,
                ]);

                return ['receipt' => $receipt, 'remote_receipt' => $remote, 'reused' => false];
            });
        } catch (\Throwable $exception) {
            // Authorization may exist remotely. Never resend this DPS blindly.
            $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'persistence']);

            throw $exception;
        }
    }

    /**
     * @return array{attempt:NfseEmissionAttempt,recovery_only:bool}
     */
    private function begin(int $invoiceId, DpsData $dps, string $origin, ?string $groupKey): array
    {
        if ($invoiceId <= 0 || !in_array($origin, [
            'manual', 'manual_group', 'automatic', 'reemit', 'substitution',
        ], true)) {
            throw new \InvalidArgumentException('Valid invoice and emission origin are required.');
        }

        $identifier = $this->recovery->dpsIdentifier($dps);
        // Fingerprint only: never retain full DPS XML, customer data or keys.
        $fingerprint = hash('sha256', json_encode(get_object_vars($dps), JSON_THROW_ON_ERROR));
        $environment = (int) $dps->tipoAmbiente;

        return DB::transaction(function () use (
            $invoiceId, $dps, $origin, $groupKey, $identifier, $fingerprint, $environment,
        ): array {
            $invoice = DB::table('documents')
                ->where('id', $invoiceId)
                ->where('type', 'invoice')
                ->lockForUpdate()
                ->first(['id', 'company_id']);

            if (!is_object($invoice) || (int) $invoice->company_id <= 0) {
                throw new \InvalidArgumentException('Invoice not found for fiscal emission.');
            }

            $companyId = (int) $invoice->company_id;
            if (function_exists('company_id') && (int) company_id() > 0 && (int) company_id() !== $companyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Cannot issue for another company.');
            }

            $previous = NfseEmissionAttempt::query()
                ->where('company_id', $companyId)
                ->where('environment', $environment)
                ->where('dps_identifier', $identifier)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($previous instanceof NfseEmissionAttempt) {
                if ((int) $previous->invoice_id !== $invoiceId || (string) ($previous->emission_group_key ?? '') !== (string) ($groupKey ?? '')) {
                    throw new \LogicException('DPS identifier already belongs to another invoice or fiscal group.');
                }

                if (in_array((string) $previous->status, [self::PENDING, self::AMBIGUOUS, self::AUTHORIZED], true)) {
                    if (!hash_equals((string) $previous->payload_fingerprint, $fingerprint)) {
                        throw new \LogicException('DPS content changed before its previous result was reconciled.');
                    }

                    return [
                        'attempt' => $previous,
                        'recovery_only' => $previous->status !== self::AUTHORIZED,
                    ];
                }
            }

            $national = preg_replace('/\\D+/', '', (string) $dps->codigoTributacaoNacional) ?: '';
            $municipal = preg_replace('/\\D+/', '', (string) $dps->codigoTributacaoMunicipal) ?: '';
            $competence = trim((string) ($dps->dataCompetencia ?? ''));

            $attempt = NfseEmissionAttempt::query()->create([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'emission_group_key' => $groupKey,
                'dps_identifier' => $identifier,
                'environment' => $environment,
                'attempt_number' => ((int) ($previous?->attempt_number ?? 0)) + 1,
                'payload_fingerprint' => $fingerprint,
                'municipio_ibge' => (string) $dps->municipioIbge,
                'codigo_tributacao_nacional' => $national,
                'codigo_tributacao_municipal' => $municipal,
                'codigo_servico' => $national . $municipal,
                'competence_date' => $competence !== '' ? $competence : null,
                'origin' => $origin,
                'status' => self::PENDING,
                'started_at' => now(),
            ]);

            return ['attempt' => $attempt, 'recovery_only' => false];
        });
    }

    private function recoverSafely(NfseClientInterface $client, DpsData $dps): ?ReceiptData
    {
        try {
            return $this->recovery->recover($client, $dps);
        } catch (\Throwable) {
            // Read error or nonexistence is NOT proof that POST failed.
            return null;
        }
    }

    /** @param array<string,mixed> $attributes */
    private function transition(NfseEmissionAttempt $attempt, string $status, array $attributes = []): void
    {
        DB::transaction(static function () use ($attempt, $status, $attributes): void {
            $locked = NfseEmissionAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ((string) $locked->status === self::AUTHORIZED && $status !== self::AUTHORIZED) {
                return;
            }

            $locked->update([
                ...$attributes,
                'status' => $status,
                'resolved_at' => in_array($status, [self::AUTHORIZED, self::REJECTED, self::TECHNICAL_FAILURE], true)
                    ? now()
                    : null,
            ]);
        });
    }

    /**
     * No public/UI route is introduced here. Reads must be authorized and
     * scoped against both the recorded tenant and the current invoice tenant.
     * @return \Illuminate\Database\Eloquent\Collection<int,NfseEmissionAttempt>
     */
    public function forInvoice(int $companyId, int $invoiceId): \Illuminate\Database\Eloquent\Collection
    {
        if ($companyId <= 0 || $invoiceId <= 0 ||
            !function_exists('company_id') || (int) company_id() !== $companyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Fiscal attempt history is company-restricted.');
        }

        return NfseEmissionAttempt::query()
            ->where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)
            ->whereHas('invoice', static fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('type', 'invoice'))
            ->orderBy('id')
            ->get();
    }
}
