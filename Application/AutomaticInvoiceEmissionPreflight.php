<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Support\InvoiceAutomaticTakerEligibility;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;

/**
 * Determines whether an Akaunting invoice can be emitted automatically
 * without an operator choosing a fiscal group.
 *
 * It performs no mutable fiscal operation.
 */
final class AutomaticInvoiceEmissionPreflight
{
    public function __construct(
        private readonly InvoiceFiscalContextResolver $context = new InvoiceFiscalContextResolver(),
        private readonly FiscalGroupReceiptState $receiptState = new FiscalGroupReceiptState(),
        private readonly FiscalProfileEmissionReadiness $profileReadiness = new FiscalProfileEmissionReadiness(),
        private readonly InvoiceAutomaticTakerEligibility $takerEligibility = new InvoiceAutomaticTakerEligibility(),
    ) {
    }

    /**
     * @return array{
     *   status:'ready'|'blocked'|'already_issued',
     *   reason:?string,
     *   group:?array<string,mixed>,
     *   details:list<string>
     * }
     */
    public function evaluate(Invoice $invoice): array
    {
        $invoiceId = is_numeric($invoice->id ?? null) ? (int) $invoice->id : 0;

        if ($invoiceId <= 0) {
            return $this->blocked('invalid_invoice');
        }

        $taker = $this->takerEligibility->evaluate($invoice);

        if (($taker['eligible'] ?? false) !== true) {
            return $this->blocked(
                (string) ($taker['reason'] ?? 'taker_requires_review'),
                is_array($taker['details'] ?? null)
                    ? array_values(array_map('strval', $taker['details']))
                    : [],
            );
        }

        $profile = $this->context->profile($invoice);
        $readiness = $this->profileReadiness->evaluate($profile);

        if (($readiness['isReady'] ?? false) !== true) {
            return $this->blocked(
                'invalid_profile',
                is_array($readiness['issues'] ?? null)
                    ? array_values(array_map('strval', $readiness['issues']))
                    : [],
            );
        }

        $groups = $this->context->groups($invoice);

        if ($groups === []) {
            return $this->blocked('no_fiscal_group');
        }

        $remaining = $this->receiptState->remaining($invoiceId, $groups);

        if ($remaining === []) {
            return [
                'status' => 'already_issued',
                'reason' => null,
                'group' => null,
                'details' => [],
            ];
        }

        if (count($remaining) !== 1) {
            return $this->blocked(
                'requires_group_selection',
                array_values(array_filter(array_map(
                    static fn (array $group): string => trim((string) ($group['key'] ?? '')),
                    $remaining,
                ))),
            );
        }

        return [
            'status' => 'ready',
            'reason' => null,
            'group' => $remaining[0],
            'details' => [],
        ];
    }

    /**
     * @param list<string> $details
     * @return array{status:'blocked',reason:string,group:null,details:list<string>}
     */
    private function blocked(string $reason, array $details = []): array
    {
        return [
            'status' => 'blocked',
            'reason' => $reason,
            'group' => null,
            'details' => $details,
        ];
    }
}
