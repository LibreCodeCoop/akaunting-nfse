<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\BulkEmissionUnit;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\GatewayException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class BulkEmissionUnitProcessor
{
    public function __construct(
        private readonly BulkEmissionUnitIssuerInterface $issuer,
        private readonly BulkEmissionUnitState $state = new BulkEmissionUnitState(),
    ) {
    }

    public function process(int $unitId): BulkEmissionUnit
    {
        $unit = BulkEmissionUnit::query()->whereKey($unitId)->first();

        if (!$unit instanceof BulkEmissionUnit) {
            throw new \RuntimeException('Bulk emission unit not found.');
        }

        $current = trim((string) $unit->status);

        if ((new BulkEmissionStatusPolicy())->isTerminal($current)) {
            return $unit;
        }

        $this->state->transition($unitId, BulkEmissionStatusPolicy::PROCESSING);

        try {
            $receipt = $this->issuer->issue(
                invoiceId: (int) $unit->invoice_id,
                emissionGroupKey: trim((string) $unit->emission_group_key),
            );

            return $this->state->transition(
                unitId: $unitId,
                target: BulkEmissionStatusPolicy::ISSUED,
                receiptId: (int) $receipt->id,
            );
        } catch (NetworkException $e) {
            return $this->state->transition(
                unitId: $unitId,
                target: BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR,
                errorType: 'network',
                errorMessage: 'DPS outcome unknown; use read-only reconciliation before retry.',
            );
        } catch (GatewayException $e) {
            $rejection = (new OfficialIssuanceRejection())->fromException($e);

            return $this->state->transition(
                unitId: $unitId,
                target: $rejection !== null
                    ? BulkEmissionStatusPolicy::REJECTED
                    : BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR,
                errorType: $rejection !== null ? 'official_rejection' : 'gateway_unconfirmed',
                errorMessage: $rejection !== null
                    ? $rejection['code'] . ': ' . $rejection['message']
                    : 'Unconfirmed SEFIN response; reconcile by DPS, not by another POST.',
            );
        } catch (SecretStoreException|PfxImportException $e) {
            return $this->state->transition(
                unitId: $unitId,
                target: BulkEmissionStatusPolicy::BLOCKED,
                errorType: 'readiness',
                errorMessage: $e->getMessage(),
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->state->transition(
                unitId: $unitId,
                target: BulkEmissionStatusPolicy::BLOCKED,
                errorType: 'preflight',
                errorMessage: $e->getMessage(),
            );
        }
    }
}
