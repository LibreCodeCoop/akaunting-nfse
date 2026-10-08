<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

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
        $remote = $this->issuer->issue($client, $dps);
        $persisted = $this->persistence->createGrouped(
            invoiceId: $invoiceId,
            receipt: $remote,
            resolvedNumber: (new ReceiptNumberResolver())->resolve($remote),
            groupKey: $groupKey,
        );

        return [
            'receipt' => $persisted,
            'remote_receipt' => $remote,
            'reused' => false,
        ];
    }
}
