<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
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
        private readonly MunicipalEmissionPreflight $municipalPreflight = new MunicipalEmissionPreflight(),
        private readonly ?DpsIssuanceIdentityContract $identity = null,
    ) {
    }

    /**
     * @param callable(ReceiptData):NfseReceipt $persist
     * @param (callable(DpsData):ReceiptData)|null $transmit Uses IssueInvoiceNfse by default.
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
        ?array $approximateTaxEvidence = null,
        ?int $cancelledReceiptId = null,
        ?int $substitutedReceiptId = null,
    ): array {
        // Historical homologation attempts without a reservation can only be
        // read/reconciled, never granted an uncoordinated first POST.
        $legacy = $this->historicalAttemptExists($invoiceId, $dps);
        $reservationId = null;
        if (!$legacy) {
            $prepared = $this->identity()->prepare(
                $invoiceId, $dps, $origin, $groupKey, $cancelledReceiptId, $substitutedReceiptId,
            );
            $dps = $prepared['dps'];
            $reservationId = $prepared['reservation_id'];
        }
        $registration = $this->begin($invoiceId, $dps, $origin, $groupKey, $approximateTaxEvidence, $reservationId);
        $attempt = $registration['attempt'];

        if ((string) $attempt->status === self::AUTHORIZED) {
            $existing = NfseReceipt::query()
                ->whereKey((int) $attempt->receipt_id)
                ->where('invoice_id', $invoiceId)
                ->first();

            if (!$existing instanceof NfseReceipt) {
                throw new \LogicException('Authorized DPS receipt is missing. Reconcile manually; do not repeat POST.');
            }

            if ((string) $existing->status !== 'emitted') {
                throw new \LogicException('Authorized DPS is already cancelled or substituted; use a new fiscal operation.');
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
            $claimStarted = false;
            try {
                // All mutable callers converge here (manual, grouped, send,
                // queue/batch, reissue and substitution). Recovery and already
                // authorized DPS intentionally bypass this first-POST preflight.
                $invoice = Invoice::query()->whereKey($invoiceId)->where('type', 'invoice')->first();
                if (!$invoice instanceof Invoice) {
                    throw new \InvalidArgumentException('Invoice not found for municipal preflight.');
                }
                $assessment = $this->municipalPreflight->evaluate($invoice, $dps, $groupKey);
                if (!$assessment['can_attempt']) {
                    throw new MunicipalIssuanceBlocked('Municipal issuance preflight blocked: ' . $assessment['reason']);
                }

                // Durable local journal is already committed. A first-POST
                // claim is irreversible in the shared MySQL authority. Any
                // timeout here is ambiguous, not permission to try again.
                $claimStarted = true;
                $this->identity()->claimFirstPost(
                    (int) $attempt->company_id, $invoiceId, (int) $reservationId,
                    $dps, (string) $attempt->payload_fingerprint,
                );
                $remote = $transmit !== null
                    ? $transmit($dps)
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
            } catch (SecretStoreException|PfxImportException $exception) {
                // Known pre-network signing/secret failures cannot have sent a
                // POST, but the consumed central claim is never released.
                $this->transition($attempt, self::TECHNICAL_FAILURE, ['failure_class' => 'local_preflight']);
                throw $exception;
            } catch (\InvalidArgumentException|\LogicException $exception) {
                $this->transition(
                    $attempt,
                    $claimStarted ? self::AMBIGUOUS : self::TECHNICAL_FAILURE,
                    ['failure_class' => $claimStarted ? 'first_post_uncertain' : 'local_preflight'],
                );
                throw $exception;
            } catch (\Throwable $exception) {
                // We cannot prove that an arbitrary failure occurred before POST.
                $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'unconfirmed']);

                throw $exception;
            }
        }

        // A typed 2xx response is not enough to claim fiscal authorization.
        // Some gateway/library versions omit nNFSe from JSON and must read
        // the actual authorized XML. Never persist a receipt without a number
        // and access key, including when the client was injected by callers.
        if (trim($remote->chaveAcesso) === '' || (new ReceiptNumberResolver())->resolve($remote) === '') {
            $this->transition($attempt, self::AMBIGUOUS, ['failure_class' => 'incomplete_receipt']);

            throw new NetworkException(
                'Incomplete authorized NFS-e receipt; reconcile by DPS before retrying issuance.',
            );
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
    private function begin(
        int $invoiceId,
        DpsData $dps,
        string $origin,
        ?string $groupKey,
        ?array $approximateTaxEvidence,
        ?int $reservationId,
    ): array {
        if ($invoiceId <= 0 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $origin) !== 1) {
            throw new \InvalidArgumentException('Valid invoice and emission origin are required.');
        }

        $identifier = $this->recovery->dpsIdentifier($dps);
        // Fingerprint only: never retain full DPS XML, customer data or keys.
        $fingerprint = hash('sha256', json_encode(get_object_vars($dps), JSON_THROW_ON_ERROR));
        $environment = (int) $dps->tipoAmbiente;

        return DB::transaction(function () use (
            $invoiceId, $dps, $origin, $groupKey, $identifier, $fingerprint, $environment,
            $approximateTaxEvidence, $reservationId,
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
            if ($approximateTaxEvidence !== null) {
                $source = $approximateTaxEvidence['source'] ?? null;
                if (is_array($source)
                    && ((int) ($source['company_id'] ?? 0) !== $companyId
                        || (string) ($source['national_service_code'] ?? '') !== (string) $dps->codigoTributacaoNacional
                        || (string) ($source['competence'] ?? '') !== (string) ($dps->dataCompetencia ?? '')
                        || (int) ($source['simples_option'] ?? 0) !== (int) $dps->opcaoSimplesNacional)) {
                    throw new \LogicException('Approximate tax source belongs to a different fiscal context.');
                }
            }
            if (function_exists('company_id') && (int) company_id() > 0 && (int) company_id() !== $companyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Cannot issue for another company.');
            }

            if ($reservationId !== null) {
                $this->identity()->assertLocal($companyId, $invoiceId, $reservationId, $dps);
            }
            $previous = NfseEmissionAttempt::query()
                ->where('company_id', $companyId)
                ->where('environment', $environment)
                ->where('dps_identifier', $identifier)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($previous instanceof NfseEmissionAttempt) {
                if ((int) ($previous->dps_reservation_id ?? 0) !== (int) ($reservationId ?? 0)) {
                    throw new \LogicException('Existing fiscal attempt uses a different immutable DPS reservation.');
                }
                if ((int) $previous->invoice_id !== $invoiceId || (string) ($previous->emission_group_key ?? '') !== (string) ($groupKey ?? '')) {
                    throw new \LogicException('DPS identifier already belongs to another invoice or fiscal group.');
                }

                // An authoritative E0312 applies only to this immutable DPS.
                // Do not repeat its rejected POST without a changed payload;
                // another competence/company/group remains an independent case.
                if ((string) $previous->status === self::REJECTED
                    && (string) ($previous->official_code ?? '') === 'E0312'
                    && in_array((int) ($previous->http_status ?? 0), [400, 422], true)
                    && hash_equals((string) $previous->payload_fingerprint, $fingerprint)) {
                    throw new MunicipalIssuanceBlocked('SEFIN E0312 already rejected this exact DPS; review its fiscal context.');
                }

                // Rejection does not prove that national sequencing permits
                // another POST of the same logical fiscal operation.
                if ((string) $previous->status === self::REJECTED) {
                    throw new MunicipalIssuanceBlocked('Rejected DPS identity is consumed; review fiscal policy before a new operation.');
                }
                if (in_array((string) $previous->status, [self::PENDING, self::AMBIGUOUS, self::AUTHORIZED], true)) {
                    if (!hash_equals((string) $previous->payload_fingerprint, $fingerprint)) {
                        throw new \LogicException('DPS content changed before its previous result was reconciled.');
                    }

                    // A source revision may change while an earlier POST is
                    // ambiguous. The identical DPS fingerprint permits ONLY
                    // read-only recovery; never replace the pinned provenance
                    // on the previous attempt and never send a second POST.
                    return [
                        'attempt' => $previous,
                        'recovery_only' => $previous->status !== self::AUTHORIZED,
                    ];
                }
            }

            if ($reservationId === null) {
                throw new \LogicException('Historical DPS has no new first-POST permission.');
            }

            $national = preg_replace('/\\D+/', '', (string) $dps->codigoTributacaoNacional) ?: '';
            $municipal = preg_replace('/\\D+/', '', (string) $dps->codigoTributacaoMunicipal) ?: '';
            $competence = trim((string) ($dps->dataCompetencia ?? ''));

            $attempt = NfseEmissionAttempt::query()->create([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'dps_reservation_id' => $reservationId,
                'emission_group_key' => $groupKey,
                'dps_identifier' => $identifier,
                'environment' => $environment,
                'attempt_number' => ((int) ($previous?->attempt_number ?? 0)) + 1,
                'payload_fingerprint' => $fingerprint,
                'approximate_tax_evidence' => $approximateTaxEvidence,
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

    private function identity(): DpsIssuanceIdentityContract
    {
        return $this->identity ?? app(DpsIssuanceIdentityContract::class);
    }

    private function historicalAttemptExists(int $invoiceId, DpsData $dps): bool
    {
        $invoice = DB::table('documents')->where('id', $invoiceId)
            ->where('type', 'invoice')->first(['company_id']);
        if (!is_object($invoice)) {
            return false;
        }

        return NfseEmissionAttempt::query()
            ->where('company_id', (int) $invoice->company_id)
            ->where('invoice_id', $invoiceId)
            ->where('environment', (int) $dps->tipoAmbiente)
            ->where('dps_identifier', $this->recovery->dpsIdentifier($dps))
            ->whereNull('dps_reservation_id')
            ->exists();
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
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }
}
