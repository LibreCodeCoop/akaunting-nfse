<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\DpsIssuanceIdentityContract;
use Modules\Nfse\Application\DpsOperationKey;
use Modules\Nfse\Application\RuntimeDpsFactory;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Purely synthetic first-POST authority for existing fake-SEFIN feature
 * regression tests. The real shared MySQL coordinator is covered separately.
 * No permissive production binding is registered by this fixture.
 */
final class SyntheticDpsIssuanceIdentity implements DpsIssuanceIdentityContract
{
    /** @var array<string,array{dps:DpsData,reservation_id:int,operation_key:string}> */
    private array $operations = [];
    /** @var array<int,array{company:int,invoice:int,series:string,number:string,environment:int}> */
    private array $reservations = [];
    /** @var array<int,true> */
    private array $claimed = [];
    /** @var array<string,int> */
    private array $sequence = [];

    public static function install(): self
    {
        $instance = new self();
        app()->instance(DpsIssuanceIdentityContract::class, $instance);

        return $instance;
    }

    public function prepare(
        int $invoiceId,
        DpsData $draft,
        string $origin,
        ?string $groupKey = null,
        ?int $cancelledReceiptId = null,
        ?int $substitutedReceiptId = null,
    ): array {
        $owner = DB::table('documents')->where('id', $invoiceId)
            ->where('type', 'invoice')->value('company_id');
        if (!is_numeric($owner) || (int) $owner <= 0
            || (function_exists('company_id') && (int) company_id() > 0
                && (int) company_id() !== (int) $owner)) {
            throw new AuthorizationException('Cannot issue a foreign fiscal invoice.');
        }

        $environment = (int) $draft->tipoAmbiente;
        $operationKey = DpsOperationKey::forInvoice(
            (int) $owner, $invoiceId, $environment,
            $groupKey, $cancelledReceiptId, $substitutedReceiptId,
        );
        if (isset($this->operations[$operationKey])) {
            return $this->operations[$operationKey];
        }

        $series = (string) $draft->serie;
        $scope = $draft->municipioIbge . $draft->cnpjPrestador . ':' . $environment . ':' . $series;
        $number = max(((int) ($this->sequence[$scope] ?? 0)) + 1, (int) $draft->numeroDps);
        $this->sequence[$scope] = $number;
        $id = count($this->reservations) + 1;
        $payload = get_object_vars($draft);
        $payload['numeroDps'] = (string) $number;
        $dps = (new RuntimeDpsFactory())->make($payload);
        $this->reservations[$id] = [
            'company' => (int) $owner,
            'invoice' => $invoiceId,
            'series' => (string) $dps->serie,
            'number' => (string) $dps->numeroDps,
            'environment' => $environment,
        ];

        return $this->operations[$operationKey] = [
            'dps' => $dps,
            'reservation_id' => $id,
            'operation_key' => $operationKey,
        ];
    }

    public function assertLocal(int $companyId, int $invoiceId, int $reservationId, DpsData $dps): object
    {
        $record = $this->reservations[$reservationId] ?? null;
        if ($record === null
            || $record['company'] !== $companyId || $record['invoice'] !== $invoiceId
            || $record['environment'] !== (int) $dps->tipoAmbiente
            || $record['series'] !== (string) $dps->serie
            || $record['number'] !== (string) $dps->numeroDps) {
            throw new \LogicException('Synthetic reservation mismatch.');
        }

        return (object) $record;
    }

    public function claimFirstPost(int $companyId, int $invoiceId, int $reservationId, DpsData $dps, string $fingerprint): void
    {
        $this->assertLocal($companyId, $invoiceId, $reservationId, $dps);
        if (isset($this->claimed[$reservationId])) {
            throw new \LogicException('Synthetic fiscal first POST already claimed.');
        }

        $this->claimed[$reservationId] = true;
    }
}
