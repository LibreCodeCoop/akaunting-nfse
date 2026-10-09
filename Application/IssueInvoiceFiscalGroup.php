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
        private readonly FiscalGroupDpsIdentity $identity = new FiscalGroupDpsIdentity(),
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

        $existing = $this->persistence->findGrouped($invoiceId, $groupKey);

        if ($existing instanceof NfseReceipt) {
            return [
                'receipt' => $existing,
                'remote_receipt' => null,
                'reused' => true,
            ];
        }

        $identity = $this->identity->forGroup($invoiceId, $groupKey);
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
        $payload['discriminacao'] = implode(
            ' | ',
            array_values(array_filter(array_map(
                static fn (array $item): string => trim((string) ($item['name'] ?? '')),
                $groupItems,
            ), static fn (string $line): bool => $line !== '')),
        );
        $payload['serie'] = $identity['series'];
        $payload['numeroDps'] = $identity['number'];

        $dps = $this->dpsFactory->make($payload);
        return $this->attempts->issue(
            client: $client,
            dps: $dps,
            invoiceId: $invoiceId,
            origin: $origin,
            groupKey: $groupKey,
            transmit: fn (): ReceiptData => $this->issuer->issue($client, $dps),
            persist: fn (ReceiptData $remote): NfseReceipt => $this->persistence->createGrouped(
                invoiceId: $invoiceId,
                receipt: $remote,
                resolvedNumber: (new ReceiptNumberResolver())->resolve($remote),
                groupKey: $groupKey,
            ),
        );
    }
}
