<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Models\Document\Document;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;

/**
 * Makes emit-on-send a fail-closed capability of Akaunting's native send job.
 *
 * DocumentSending is dispatched before Akaunting notifies the customer. Any
 * preflight or fiscal issuance exception therefore prevents customer delivery
 * from falsely implying that an NFS-e was authorized.
 */
final class EmitNfseBeforeDocumentSend
{
    /** @var \Closure(string,mixed):mixed */
    private readonly \Closure $settingResolver;

    public function __construct(
        private readonly AutomaticInvoiceEmissionPreflight $preflight,
        private readonly BulkEmissionUnitIssuerInterface $issuer,
        ?\Closure $settingResolver = null,
    ) {
        $this->settingResolver = $settingResolver
            ?? static fn (string $key, mixed $default): mixed => function_exists('setting')
                ? \setting($key, $default)
                : $default;
    }

    public function handle(object $event): void
    {
        $document = $event->document ?? null;

        if (!$document instanceof Document || (string) ($document->type ?? '') !== 'invoice') {
            return;
        }

        $policy = strtolower(trim((string) ($this->settingResolver)(
            'nfse.emission_policy',
            'manual',
        )));

        if ($policy !== 'emit_on_send') {
            return;
        }

        $result = $this->preflight->evaluate($document);
        $status = (string) ($result['status'] ?? 'blocked');

        if ($status === 'already_issued') {
            return;
        }

        if ($status !== 'ready' || !is_array($result['group'] ?? null)) {
            $reason = trim((string) ($result['reason'] ?? 'preflight_blocked'));

            throw new \LogicException(
                'Automatic NFS-e emission blocked before document send: ' . $reason,
            );
        }

        $groupKey = trim((string) ($result['group']['key'] ?? ''));

        if ($groupKey === '') {
            throw new \LogicException(
                'Automatic NFS-e emission blocked before document send: missing_fiscal_group',
            );
        }

        $this->issuer->issue((int) $document->id, $groupKey);
    }
}
