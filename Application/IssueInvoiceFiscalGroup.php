<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

/**
 * Issues exactly one deterministic fiscal group of an Akaunting invoice.
 *
 * Existing persisted groups are returned before any mutable POST. New attempts
 * allocate a stable DPS identity first so ambiguous transport failures can be
 * recovered through the ordinary DPS recovery contract.
 */
final class IssueInvoiceFiscalGroup
{
    public function __construct(
        private readonly IssueInvoiceNfse $issuer = new IssueInvoiceNfse(),
        private readonly ReceiptPersistence $persistence = new ReceiptPersistence(),
        private readonly RuntimeDpsFactory $dpsFactory = new RuntimeDpsFactory(),
        private readonly EmissionAttemptJournal $attempts = new EmissionAttemptJournal(),
    ) {
    }

    /**
     * @param array<string,mixed> $group
     * @return array{receipt:NfseReceipt,remote_receipt:?ReceiptData,reused:bool}
     */
    public function issue(
        NfseClientInterface $client,
        int $invoiceId,
        DpsData $baseDps,
        array $group,
        string $origin = 'manual_group',
        ?string $additionalDescription = null,
        ?string $descriptionOverride = null,
        ?array $approximateTaxEvidence = null,
        ?NfseReceipt $substitutionOriginal = null,
    ): array {
        $groupKey = trim((string) ($group['key'] ?? ''));
        $category = trim((string) ($group['rtc_supply_category'] ?? ''));

        // Enforce capability at the last mutable boundary as well as in the
        // HTTP/queue preflights; unsupported operations cannot be re-encoded
        // as ordinary LC116 services by a caller.
        if ($category !== '' && $category !== 'ordinary_lc116') {
            throw new \LogicException('Unsupported RTC supply category for LC116 DPS issuance: ' . $category);
        }

        if ($invoiceId <= 0 || $groupKey === '') {
            throw new \InvalidArgumentException('Invoice and fiscal group identity are required.');
        }

        // Even a cached authorized receipt must not be returned cross-tenant.
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId > 0 && !DB::table('documents')
            ->where('id', $invoiceId)
            ->where('type', 'invoice')
            ->where('company_id', $companyId)
            ->exists()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot issue another company invoice.');
        }

        if ($substitutionOriginal instanceof NfseReceipt
            && ((int) $substitutionOriginal->invoice_id !== $invoiceId
                || (string) $substitutionOriginal->emission_group_key !== $groupKey)) {
            throw new \LogicException('Replacement must target the original receipt of this fiscal group.');
        }

        $existing = $this->persistence->findGrouped($invoiceId, $groupKey);
        if ($substitutionOriginal instanceof NfseReceipt && $existing instanceof NfseReceipt
            && (int) $existing->id === (int) $substitutionOriginal->id
            && (string) $existing->status !== 'emitted') {
            throw new \LogicException('A cancelled or already substituted fiscal group cannot be replaced again.');
        }
        if ($substitutionOriginal instanceof NfseReceipt && $existing instanceof NfseReceipt
            && (int) $existing->id !== (int) $substitutionOriginal->id
            && (int) $existing->replaces_receipt_id !== (int) $substitutionOriginal->id) {
            throw new \LogicException('Group receipt is already associated with another fiscal operation.');
        }
        if ($existing instanceof NfseReceipt && ($substitutionOriginal === null
            || (int) $existing->id !== (int) $substitutionOriginal->id)) {
            return [
                'receipt' => $existing,
                'remote_receipt' => null,
                'reused' => true,
            ];
        }

        // The historical fiscal-group ID is NOT a national DPS sequence.
        $payload = get_object_vars($baseDps);
        $payload['itemListaServico'] = trim((string) ($group['item_lista_servico'] ?? ''));
        $payload['codigoTributacaoNacional'] = preg_replace(
            '/\D+/',
            '',
            (string) ($group['codigo_tributacao_nacional'] ?? ''),
        ) ?: '';
        if (array_key_exists('codigo_tributacao_municipal', $group)) {
            $payload['codigoTributacaoMunicipal'] = preg_replace(
                '/\D+/',
                '',
                (string) $group['codigo_tributacao_municipal'],
            ) ?: '';
        }
        $payload['codigoNbs'] = trim((string) ($group['codigo_nbs'] ?? ''));
        if (!(new \Modules\Nfse\Support\NbsEmissionReadiness())->valid(
            $payload['codigoNbs'],
            ($payload['ibsCbsFinalidade'] ?? null) !== null,
        )) {
            throw new \InvalidArgumentException('Missing or invalid NBS service code for IBS/CBS emission.');
        }
        $payload['valorServico'] = trim((string) ($group['amount'] ?? ''));
        $payload['aliquota'] = trim((string) ($group['aliquota'] ?? ''));
        $groupItems = is_array($group['items'] ?? null) ? $group['items'] : [];
        $payload['discriminacao'] = (new FiscalDescriptionComposer())->group(
            $groupItems,
            $additionalDescription,
            $descriptionOverride,
        );
        $dps = $this->dpsFactory->make($payload);
        return $this->attempts->issue(
            client: $client,
            dps: $dps,
            invoiceId: $invoiceId,
            origin: $substitutionOriginal instanceof NfseReceipt ? 'substitution' : $origin,
            groupKey: $groupKey,
            substitutedReceiptId: $substitutionOriginal instanceof NfseReceipt
                ? (int) $substitutionOriginal->id : null,
            approximateTaxEvidence: $approximateTaxEvidence,
            transmit: fn (DpsData $reserved): ReceiptData => $this->issuer->issue($client, $reserved),
            persist: fn (ReceiptData $remote): NfseReceipt => $substitutionOriginal instanceof NfseReceipt
                ? $this->persistence->createReplacement(
                    invoiceId: $invoiceId,
                    receipt: $remote,
                    resolvedNumber: (new ReceiptNumberResolver())->resolve($remote),
                    original: $substitutionOriginal,
                )
                : $this->persistence->createGrouped(
                    invoiceId: $invoiceId,
                    receipt: $remote,
                    resolvedNumber: (new ReceiptNumberResolver())->resolve($remote),
                    groupKey: $groupKey,
                ),
        );
    }
}
